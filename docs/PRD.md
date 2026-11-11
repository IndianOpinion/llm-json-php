# llm-json-php: product requirements

Status: draft for review. Audience: the builder of version 0.1.0 and anyone
who later has to change it. Every rule here has a reason; the reasons are in
section 14.

Package: `indianopinion/llm-json-php`. Namespace: `IndianOpinion\LlmJson`.
Licence: MIT.

## 1. Problem

A PHP application that wants a structured answer from a language model has
to do far more than one HTTP request. Without that work, things go wrong in
quiet ways that nobody sees until readers do:

- **Output that parses but is wrong.** Under strict JSON schema decoding, a
  model that types a straight double quote inside a text value ends the
  string there. The JSON still parses, every key is present, and the
  paragraph stops mid-sentence. The spill-over sometimes lands as an extra
  array item that starts in lower case. Nothing errors.
- **Output that was corrupted after the model.** A third-party AI writing
  plugin silently inserted typos and zero-width characters into generated
  text. Again nothing errored; the damage was found by reading pages.
- **Truncated output.** Providers return cut-off bodies, sometimes mid-key.
  Measured: one inexpensive model returned between 15 and 33 truncated
  (under 100 token) structured responses every day for five days running.
- **Credit exhaustion that stops everything.** One provider answers 402 when
  the balance is empty; another answers 429 with a quota code in the body,
  which looks like an ordinary rate limit. Measured: a provider account ran
  out of credit early one afternoon and every call failed from then on;
  with no automatic fallback and no alert, downstream work stopped until a
  human noticed.
- **Spurious credit errors.** A request sent with no output token cap makes
  some routers reserve credit against the model's full default ceiling, so
  a healthy but modest balance gets a 402. Measured: adding a cap of about
  four times the largest observed output removed those errors, and the cap
  never truncated real work.
- **Fallback that is too eager or not eager enough.** Measured: a single
  transport blip on the primary model skipped the sibling model entirely
  and went straight to the last-resort provider at several times the cost.
  The opposite failure is retrying a dead model on every call.
- **Invisible failures.** Measured: in a cron context the PHP error log went
  nowhere, so retries, fallbacks and guard actions were happening with no
  record at all.

Measured result of doing the work: after a bounded same-model retry was
added, the first truncated response it met (cut off mid-key) was retried and
succeeded about ten seconds later, instead of falling to a costlier
provider.

This library packages that work: one call in, a validated PHP array out, or
a typed exception that says exactly what went wrong.

## 2. Goals and non-goals

### Goals

1. One method call sends a chat completions request to an OpenAI-compatible
   endpoint and returns a PHP array that matches a JSON schema the caller
   supplied.
2. Works with OpenRouter and OpenAI out of the box, and with any compatible
   base URL (local servers, gateways, other vendors).
3. Uses strict structured outputs where the route supports them; falls back
   to JSON mode plus local validation, and then to prompt-only plus local
   validation, where it does not.
4. Tries an ordered chain of routes (provider plus model).
5. Puts a route on cooldown after repeated failure, using a pluggable store:
   in memory, file, PSR-16 cache, or WordPress transients via a small
   adapter.
6. Detects credit exhaustion (HTTP 402 and provider-specific bodies), skips
   to the next provider, and raises or reports a distinct signal the caller
   can alert on.
7. Retries with backoff on 429 (rate limit), 5xx and transport errors.
8. Enforces connect, request and total timeouts.
9. Runs a guard on the parsed output that rejects truncated JSON, missing
   required fields, zero-width and control characters, and prose that ends
   mid-sentence. The guard is configured per purpose.
10. Returns token and cost accounting with every result, including the
    tokens spent on failed attempts.
11. Has a pluggable HTTP transport: curl by default, a WordPress adapter for
    `wp_remote_post`, and an interface for anything else.
12. Logs through PSR-3 when a logger is given; never writes to the PHP error
    log on its own.
13. Fails with clear, typed exceptions. Never fails silently.
14. Zero runtime dependencies. Usable with or without Composer.

### Non-goals

- Prompt management, templating or prompt versioning.
- Streaming responses.
- Embeddings, images, audio, moderation endpoints.
- Agents, tool calling loops, function calling, multi-turn memory.
- A built-in price list. Prices change; the caller supplies them, or the
  provider reports cost.
- Rate limiting or budgeting across processes (the caller owns spend caps;
  the library reports spend so the caller can enforce them).
- Persisting usage rows. The library emits an event per attempt; the caller
  stores it where it likes.
- The OpenAI Responses API (see open question Q1).

## 3. Users and setting

- **PHP developers** in plain PHP applications and frameworks, on PHP 8.1
  or later, who want structured model output without an SDK and its
  dependency tree.
- **WordPress plugin and theme authors** who must route HTTP through
  `wp_remote_post` (proxies, logging, host rules) and keep state in
  transients. They may not use Composer at all.
- **Operators of cron and queue workers** that make thousands of calls a day
  unattended, where a silent failure can run for hours.

Where it runs: web requests (short budgets, often a 30 second execution
limit), WP-Cron, system cron under the CLI (long budgets, no visible error
log), and long-running queue workers (in-memory state survives between
calls). Several processes on one host, or several hosts, may call at the
same time and share one cooldown store.

## 4. Behaviour

Cases are numbered once across the whole section. Section 12 maps every
case to at least one acceptance test.

### 4.1 Vocabulary

- **Provider**: a base URL, an API key, a kind (`openrouter`, `openai` or
  `generic`), optional extra headers, and capability settings.
- **Route**: a provider plus a model id, with optional per-route settings
  (output mode, token cap, temperature, reasoning switch, provider routing
  preferences, timeouts).
- **Chain**: an ordered list of routes. The first route is the primary.
- **Attempt**: one HTTP request to one route.
- **Call**: one public method invocation. A call makes one or more attempts.
- **Purpose**: a short caller label (for example `summary` or `classify`)
  that selects a guard profile and appears in logs, events and keys.
- **Output mode**: `strict` (JSON schema enforced by the provider), `json`
  (JSON object mode, schema given in the prompt, validated locally) or
  `prompt` (no response format parameter; schema in the prompt; validated
  locally). `auto` picks for the caller.
- **Account-level failure**: a failure that belongs to the provider account,
  not the model: credit exhaustion, invalid key, forbidden.

### 4.2 Request validation (before any network traffic)

1. A call with an empty chain, after per-request overrides, throws a
   configuration exception. No request is made.
2. A provider with an empty API key is rejected at construction, unless the
   provider is `generic` and explicitly marked as keyless (local servers).
3. A base URL that is not `https` is rejected, except for `localhost`,
   `127.0.0.1` and `::1`, or when the provider is explicitly marked as
   allowing plain HTTP. Reason: the API key travels in a header.
4. A schema that is not a JSON object with `type` `object` at the root is
   rejected. Providers require an object root for structured output, and
   the guard walks named fields.
5. A schema name that is empty or contains characters outside
   letters, digits, underscore and hyphen, or is longer than 64 characters,
   is rejected (provider rules for the schema name).
6. A route set to `strict` mode with a schema that is not strict-compatible
   (every property listed in `required`, `additionalProperties` false on
   every object, only supported keywords) is rejected with a message that
   names the first offending JSON path. The library never rewrites the
   caller's schema silently. A route in `auto` mode with such a schema uses
   `json` mode instead and records why.
7. Temperature outside 0 to 2 is rejected (not clamped: a typo should fail
   loudly). Token cap below 1 is rejected.
8. A purpose label longer than 64 characters, or containing characters
   outside letters, digits, underscore, hyphen and dot, is rejected. It is
   used in store keys and logs.
9. An empty system instruction and an empty user input together are
   rejected. Either one alone may be empty.
10. A guard profile that names a field path not present in the schema is
    rejected at profile construction. A typo in a path would otherwise turn
    a guard off without anyone knowing.

### 4.3 Building the request body

11. Every attempt sends `model`, `messages` (system, then user, or the
    caller's message list), and an output token cap. The cap is always
    sent. Default 8192. OpenAI kind uses `max_completion_tokens`; OpenRouter
    and generic kinds use `max_tokens`; the parameter name is overridable
    per provider.
12. `strict` mode sends a response format of type `json_schema` with the
    schema name, the schema and `strict` true.
13. `json` mode sends a response format of type `json_object` and appends a
    schema instruction to the system message: a compact rendering of the
    schema and the sentence that the reply must be one JSON object and
    nothing else.
14. `prompt` mode sends no response format and appends the same schema
    instruction.
15. On an OpenRouter route, the body asks for usage accounting in the
    response, and asks the router to send the request only to upstream
    hosts that honour every parameter sent (so strict schema and
    temperature are never silently dropped). Both are on by default and can
    be turned off per route.
16. On an OpenRouter route, optional upstream host preferences (an ordered
    list, and whether other hosts may be used as fallback) pass through
    unchanged when the route sets them.
17. A route may turn reasoning off. On OpenRouter this sends the reasoning
    switch disabled; on OpenAI it sends the lowest reasoning effort the
    caller configures. Reason: hidden reasoning tokens are billed as output
    and consume the token cap.
18. Temperature is sent only when the caller sets it.
19. Optional attribution headers (OpenRouter's referer and title) are sent
    only when the caller configures them. The library sends no identifying
    header of its own except a `User-Agent` naming the library and version.
20. An optional instruction against straight double quotes in text values
    (section 4.8, rule G9) is appended to the system message when the
    purpose's guard profile enables it.
21. Extra body fields set on a route (for example `seed` or `top_p`) are
    merged last but may not overwrite `model`, `messages`, the response
    format or the token cap. Attempting to is a configuration exception.
22. The JSON body is encoded with unescaped slashes and unescaped Unicode.
    An encoding failure (invalid UTF-8 in the caller's input) throws a
    configuration exception naming the field, before any request.

### 4.4 Transport

23. The default transport uses curl, with a connect timeout (default 10
    seconds) and a total request timeout (default 90 seconds), TLS
    verification on, HTTP/1.1 or HTTP/2 as curl negotiates, and no redirect
    following.
24. A redirect response (3xx) is a configuration failure for that provider:
    not retried, not followed, and the attempt records the status. Reason:
    following a redirect can send the API key to another host, and a moved
    base URL is a setup error.
25. A response body larger than the cap (default 8 MiB) is aborted mid
    transfer and recorded as an invalid response (retryable).
26. Transport errors (DNS failure, connection refused, reset, TLS failure,
    timeout) are retryable. A TLS verification failure is the exception: it
    is not retried, because it will not fix itself in four seconds and may
    mean interception.
27. If ext-curl is missing and no other transport is given, construction
    throws a configuration exception that says to install ext-curl or pass
    the WordPress transport.
28. The WordPress transport calls `wp_remote_post` with the same headers,
    body and timeouts, maps a `WP_Error` to a transport failure with its
    message, and reads status, headers and body through the WordPress
    retrieval functions. It sets `redirection` to 0. It fails at
    construction if `wp_remote_post` does not exist.
29. Any transport receives the full request (method, URL, headers, body,
    timeouts) and returns status, headers and body, or throws the library's
    transport exception. A transport that throws anything else has that
    exception wrapped as a transport failure, with the original attached.

### 4.5 Reading a response

Each response is classified into exactly one outcome. The table in 4.6
says what each outcome does next.

30. **Good**: HTTP 200, JSON body, `choices[0].message.content` is a
    non-empty string that decodes to a JSON object. Continue to validation
    and the guard.
31. Content wrapped in one outer Markdown code fence (with or without a
    `json` tag) is unwrapped before decoding. Only one outer fence is
    removed; the unwrap is recorded in the result. This happens in every
    mode, since some providers fence even in JSON mode.
32. Leading and trailing whitespace around the body or the content is
    ignored. Some routers send whitespace while a slow upstream is still
    working.
33. Content given as a list of parts (objects with a type of `text` and a
    `text` field) is joined in order. Non-text parts are ignored.
34. Content that is null or empty with a `refusal` field present is a
    **refusal**. The refusal text is kept (truncated to 500 characters).
35. Content that is null with tool calls present is an **invalid response**
    (retryable). The library never sends tools, so this is a provider fault.
36. `finish_reason` of `length` is **truncated output**, whatever the
    content looks like, even if it happens to parse. Retryable.
37. `finish_reason` of `content_filter` is a **content filter** outcome.
    Not retried on the same route; the next route is tried.
38. Missing or empty `choices` with HTTP 200 is an **invalid response**
    (retryable).
39. HTTP 200 with a top-level `error` object is a **provider error inside a
    success** (a router reporting an upstream failure mid-route). Retryable,
    unless the error code inside is 402 or the body matches a credit
    pattern, in which case it is **credit exhausted**.
40. HTTP 200 with an `error` object inside `choices[0]` is treated the same
    as case 39.
41. HTTP 200 with an empty body, a body that is not JSON (for example an
    HTML page from a proxy), or a body that is not valid UTF-8 is an
    **invalid response** (retryable).
42. Content that does not decode as JSON is **malformed output**
    (retryable). The first 200 characters are kept for the exception.
43. Content that decodes to something other than an object (array, string,
    number, boolean, null) is a **schema violation** (retryable).
44. Duplicate keys in the content decode with the last one winning, as PHP
    does. Not an error; documented.
45. Integers outside the PHP integer range decode as floats and then fail
    an `integer` type check. That is a schema violation. Documented.
46. JSON nesting deeper than 64 levels fails to decode and is malformed
    output.
47. `usage` missing or partial is not an error. Missing token counts are
    reported as unknown (null), never as zero.
48. The `model` field in the response, when present and different from the
    requested model, is recorded as the reported model. The requested
    model stays the route's identity for cooldowns.
49. The response `id` and any request id header (`x-request-id` and
    similar) are recorded on the attempt for support tickets.
50. A `Retry-After` header on 429 or 503 is read in either form (seconds or
    an HTTP date).

### 4.6 HTTP status outcomes

51. **400, 404, 409, 413, 422**: request rejected. Not retried on the same
    route. Counts towards the route's cooldown. The next route is tried.
    Exception: in `auto` mode, a 400 or 422 whose body mentions the response
    format, `json_schema`, or structured output makes the same route retry
    once in `json` mode, and the route remembers that downgrade for the
    rest of the process.
52. **401 and 403**: authentication failure. Account-level. Not retried,
    not counted towards any cooldown. Every remaining route on the same
    provider is skipped for this call. The next route on a different
    provider is tried.
53. **402**: credit exhausted. Account-level. Same skipping rule as case
    52. A credit hold may be set (case 77).
54. **429 with a credit pattern in the body** (for example
    `insufficient_quota`, `credit_balance_exhausted`, `insufficient credits`,
    or an error code of 402 inside the body): credit exhausted, exactly as
    case 53. The pattern list is configurable per provider kind and can be
    extended.
55. **403 with a credit or billing pattern in the body**: credit exhausted,
    not authentication. Some providers use 403 for a disabled billing
    account.
56. **429 without a credit pattern**: rate limited. Retryable with backoff,
    honouring `Retry-After` up to the backoff cap. If `Retry-After` exceeds
    the cap, the route is not retried and the next route is tried.
57. **408, 425, 500, 502, 503, 504, and any other 5xx** (including the
    520 to 524 range some CDNs use): retryable.
58. **Any other status** (for example 418 or 451): not retried, counts
    towards cooldown, next route.
59. The first 300 characters of any error body are kept on the attempt,
    with anything that looks like a bearer token or an API key redacted.

### 4.7 Validation against the schema

Validation runs on every parsed output in every mode, including `strict`.
Providers enforce strict mode, but not every upstream host does it
correctly, and a router can quietly send a request to one that does not.

60. The built-in validator covers the keywords allowed in strict structured
    outputs: `type` (single or a list, which is how nullable fields are
    written), `properties`, `required`, `additionalProperties`, `items`,
    `enum`, `const`, `anyOf`, `$ref` to `$defs` within the same schema,
    `minItems`, `maxItems`, `minLength`, `maxLength`, `pattern`, `minimum`,
    `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf`. String
    lengths count Unicode code points, not bytes.
61. Every key in `required`, at every level, must be present. A missing key
    is a schema violation that names the JSON path (for example
    `/items/3/title`). Retryable.
62. A present key with the wrong type, a value outside `enum`, or an extra
    key where `additionalProperties` is false is a schema violation.
    Retryable.
63. An unsupported keyword (for example `format` or `if`) is ignored by the
    validator and logged once per schema at debug level. It is not an
    error, because the schema may be shared with other tools.
64. A `pattern` that is not a valid regular expression is a configuration
    exception at first use, not a schema violation.
65. `$ref` cycles are followed with a depth limit of 32; deeper is a
    configuration exception.

### 4.8 The guard

The guard runs after schema validation passes. It is configured per purpose
through a guard profile. A profile lists rules; some apply to every string
in the output, some to named field paths. Paths use JSON pointer syntax with
`*` meaning "every item of this array" (for example `/paragraphs/*` or
`/items/*/text`).

Each rule has an action: `reject` (the attempt fails; retryable) or, where
the rule allows it, `repair` (the value is fixed and the fix is recorded).
The default profile, used when a purpose has no profile of its own, applies
G1 to G5 with `reject` and nothing else.

66. **G1, truncated JSON.** Malformed output and `finish_reason` `length`
    (cases 36 and 42) are always rejections. This rule cannot be turned
    off; it is listed so the result report names it.
67. **G2, required fields.** Missing required fields (case 61) are always
    rejections. This rule cannot be turned off either.
68. **G3, invisible characters.** Every string value and every object key
    is scanned. These are rejected: U+200B zero width space, U+2060 word
    joiner, U+FEFF byte order mark (anywhere, including the start), U+180E
    Mongolian vowel separator, U+00AD soft hyphen, U+034F combining
    grapheme joiner, U+115F, U+1160, U+3164, U+FFA0 (Hangul fillers used as
    blanks), the bidirectional controls U+202A to U+202E and U+2066 to
    U+2069, U+200E and U+200F, and the tag characters U+E0000 to U+E007F.
69. **G3, joiners.** U+200C zero width non-joiner and U+200D zero width
    joiner are legitimate in many scripts and in emoji. They are allowed
    when both neighbours are letters or combining marks of one script in
    the profile's joining-script list (default: Devanagari, Bengali,
    Gurmukhi, Gujarati, Oriya, Tamil, Telugu, Kannada, Malayalam, Sinhala,
    Arabic, Syriac), and U+200D is allowed between two emoji or inside an
    emoji sequence. Anywhere else they are rejected. Repair action for G3:
    remove the offending characters and record how many were removed from
    which path.
70. **G4, control characters.** C0 controls other than tab, line feed and
    carriage return, the DEL character, and C1 controls (U+0080 to U+009F)
    are rejected in every string. Repair action: remove them. A profile may
    also forbid tab and newlines in named single-line fields (titles,
    labels).
71. **G5, replacement character.** U+FFFD in any string is rejected. It
    means a decoding failure upstream (mojibake), and repairing it would
    hide lost text. No repair action.
72. **G6, unfinished prose.** For each named path, a non-empty string must
    end in sentence-final punctuation, optionally followed by closing
    quotes or brackets. Sentence-final punctuation is: full stop,
    exclamation mark, question mark, ellipsis (U+2026), Devanagari danda
    and double danda (U+0964, U+0965), the ideographic full stop and the
    full-width exclamation and question marks, and the Arabic question mark
    (U+061F). Closing marks are: straight and curly double and single
    quotes, the right guillemet, closing round and square brackets. Markup
    tags and HTML entities are stripped and decoded before the check. An
    empty string is not a fragment (whether it may be empty is the schema's
    job).
73. **G6 repair.** Repair cuts the value back to the end of its last
    complete sentence. A full stop directly after a common abbreviation or
    a single capital letter (titles such as Dr or Prof, month short forms,
    currency or number short forms, and initials such as the U in U.S.) is
    not a sentence end. The abbreviation list is configurable. A value with
    no complete sentence becomes the empty string; inside an array of
    paragraphs, an empty item is removed. Nothing is ever cut in the middle
    of a sentence.
74. **G7, continuation items.** For each named array-of-strings path, an
    item after the first that begins with a lower-case letter is the tail
    of a sentence that a broken quote cut off. Reject, or repair by
    removing the item. Scripts without case are unaffected.
75. **G8, word floors.** For each named path, a minimum word count after
    repair (for an array, the total across items). If repairs left a field
    under its floor and the original was longer, the call makes one
    regeneration through the whole chain, applies the guard again, and
    keeps whichever result carries more words across the floor fields. If
    the regeneration fails outright, the first repaired result is kept. The
    result records that a regeneration happened and which result was kept.
    Words are counted as runs of letters, marks and digits, so scripts
    without spaces between letters are counted sensibly.
76. **G9, quote instruction.** When enabled, one sentence is appended to
    the system message asking the model never to type a straight double
    quote inside a text value and to use curly single quotes when quoting.
    This is prevention for the failure G6 and G7 catch.
77. **G10, script share (optional).** For each named path, at least a given
    share of letters must belong to a given script (for example 30 percent
    Devanagari), checked once the text has at least 50 letters. Catches a
    translated title over an untranslated body. Reject only.
78. **G11, mixed-script words (optional).** A single word that mixes Latin
    letters with Cyrillic or Greek letters is rejected. Catches homoglyph
    corruption by tools that sit between the model and the page. Reject
    only.
79. **G12, custom rules.** A profile may add callables. Each receives the
    data and returns a list of violations (path plus message). A callable
    that throws is a configuration fault: the exception is wrapped and
    thrown to the caller, not treated as a model failure, because it is a
    bug in the caller's code.
80. Guard rejections are retryable and count towards the route's cooldown,
    like malformed output. The model produced bad text; that is the model's
    fault.
81. When the guard repairs, the result carries the repaired data, and the
    report lists every repair (rule, path, before and after word counts or
    characters removed). The original data is available on the result for
    debugging when the caller asks to keep it (off by default).

### 4.9 Retries and backoff on one route

82. Each route allows a number of attempts (default 2, so one retry). The
    retry goes to the same route, after a delay.
83. Retryable outcomes: transport failure (except TLS verification, case
    26), invalid response, provider error inside a success, truncated
    output, malformed output, schema violation, guard rejection, 408, 425,
    429 (rate limit), 5xx.
84. Never retried on the same route: 400, 401, 402, 403, 404, 409, 413,
    422, credit exhausted in any form, refusal, content filter, redirect,
    TLS verification failure.
85. Delay before retry number n (n starting at 1): a random value between
    `base × 2^(n-1)` and `2 × base × 2^(n-1)`, capped at the maximum delay.
    Default base 2 seconds, cap 30 seconds, so the first retry waits 2 to 4
    seconds. Jitter keeps parallel workers from retrying in step.
86. When `Retry-After` is present and within the cap, the delay is
    `Retry-After` plus up to one second of jitter.
87. The sleep goes through an injectable sleeper, so tests run without
    waiting.

### 4.10 The chain

88. Routes are tried in order. A success on any route ends the call.
89. **Skip for account-level failure in this call.** After a credit or
    authentication failure on a provider, every later route on that
    provider (same base URL and key) is skipped for the rest of the call,
    and each skip is recorded.
90. **Skip for cooldown.** A route that is on cooldown is skipped and the
    skip is recorded. The next route is tried.
91. **Never skip the last route for cooldown.** If every remaining route is
    cooled, the last route in the chain is still attempted, so there is
    always a path. A credit hold (case 99) follows the same rule: holds and
    cooldowns never leave a call with zero attempts.
92. **A transport failure on one route does not skip its siblings.** The
    next route on the same provider is tried. A provider being briefly
    unreachable is not evidence that the next model on it is.
93. When every route has failed or been skipped, the call throws. If at
    least one attempt in the call hit credit exhaustion, the exception is
    the credit-exhausted exception; otherwise it is the chain-exhausted
    exception. The credit-exhausted exception extends the chain-exhausted
    exception, so one catch handles both.
94. When a later provider succeeds after an earlier one hit credit
    exhaustion, the call returns the result (it does not throw), the result
    lists the exhausted providers, the credit-exhausted event fires, and a
    critical log line is written (throttled, case 120). A caller who wants
    an exception anyway sets the strict credit option, which makes the call
    throw the credit-exhausted exception with the successful result
    attached.
95. A whole-chain failure increments a per-day failure counter in the
    store (UTC date). Observability only; nothing halts on it.
96. A per-call override can replace the chain (for example a single model
    for a test) without changing the client.
97. **Total deadline.** A call has an overall time budget (default 300
    seconds). No attempt or retry starts when less than the minimum
    useful time (default 5 seconds) remains; each attempt's request timeout
    is clipped to the time left. When the budget runs out, the call throws
    the chain-exhausted exception with a deadline reason and the attempts
    so far.
98. A refusal on one route moves to the next route. If every attempted
    route refused, the chain-exhausted exception carries the refusal as its
    last error and a flag saying all failures were refusals.

### 4.11 Cooldowns and holds

99. **Credit hold.** After credit exhaustion on a provider, the store gets
    a provider hold for a short time (default 60 seconds; 0 turns it off).
    While the hold is active, routes on that provider are skipped at the
    start of other calls too, subject to case 91. Short by default, so a
    top-up takes effect within a minute.
100. **Failure count.** After a route's attempts on one call end in failure
     that is not account-level, the route's consecutive-failure counter is
     incremented. The counter expires after the failure window (default
     1800 seconds) without new failures.
101. **Cooldown start.** When the counter reaches the threshold (default 2
     consecutive failed calls), the route is put on cooldown for the
     cooldown time (default 600 seconds) and the counter is cleared. A
     single warning line and a cooldown event are emitted when the cooldown
     starts, not on every skip.
102. **Success.** A success clears the route's failure counter. It does not
     end an active cooldown early (the route was not tried while cooled,
     except as last route; a success there does end its cooldown, since the
     route has proved itself).
103. **Account-level failures never count.** 401, 402, 403 and credit
     exhaustion do not touch the failure counter. A balance or a key is
     shared by every model on the provider, so cooling one model for it
     would only push the same error down the chain.
104. **Store keys.** Keys are a configurable prefix (default `llmjson`)
     plus a hash of the provider base URL, the key fingerprint (a short
     hash, never the key) and the model, so they are short, safe for any
     store and the same across processes. Two clients with different keys
     for the same provider do not share holds.
105. **Concurrency.** Several processes may share one store. Counter
     increments are read, add, write in most stores, so two processes
     failing at the same moment can lose an increment; the effect is that a
     cooldown starts one failure late. That is acceptable and documented.
     Setting a cooldown is idempotent. The "log once" rule uses an
     add-if-absent operation where the store has one (memory, file, and
     WordPress with an object cache that supports it); elsewhere a
     duplicate log line is possible and harmless.
106. **File store.** One file per key in a directory the caller names,
     created with mode 0700 if missing; files are 0600. Writes go to a
     temporary file and are renamed into place. Increments and add-if-absent
     hold an exclusive lock on a per-key lock file. Expired files are
     removed lazily on read and by an occasional sweep (one call in a
     hundred, at most 50 files per sweep). Key names are hex hashes, so no
     path traversal is possible. On a network filesystem without working
     locks, increments degrade to case 105.
107. **Memory store.** Lives for the process. Right for long-running
     workers and tests; useless across web requests, which is documented.
108. **PSR-16 store.** Wraps any PSR-16 cache. There is no atomic increment
     in PSR-16, so case 105 applies. Available only when the PSR-16
     interface is installed; the library does not require it.
109. **WordPress store.** Wraps transient get, set and delete passed in as
     functions, or calls the WordPress functions directly when they exist.
     Keys stay well under the transient key length limit. With no
     persistent object cache, transients live in the options table, which
     is fine at this write rate. Object caches can evict early; a lost
     cooldown means one extra attempt.
110. **Store failure fails open.** If the store throws or returns garbage,
     the call proceeds as if no route were cooled or held, logs a warning
     (throttled) and records the store error on the result. Reason: the
     store is an optimisation; a cache outage must never stop model calls.
111. **Clock.** Expiry uses the store's own TTL. The memory and file stores
     read time through an injectable clock so tests can move time.
112. **Status and reset.** The client can report, for each route in its
     chain: failure count, cooldown end time, provider hold end time, and
     the chain failure count for today. It can clear the cooldown of one
     route or of all routes.
113. **Reliability off.** One switch turns off retries, cooldowns and holds
     together: each route is tried once, in order, with no store traffic.
     The token cap, validation and guard stay on. This is the escape hatch
     when the reliability layer itself is suspected.

### 4.12 Accounting and the result

114. Every attempt that received a usage block records prompt tokens,
     completion tokens, reasoning tokens and cached prompt tokens where the
     provider gives them. Failed attempts that were billed (any HTTP 200)
     are counted; they cost money even when the output was rejected.
115. Cost per attempt comes from, in order: the provider-reported cost in
     the usage block (OpenRouter reports it); the caller's price table
     (input and output price per million tokens, per model id, optional
     cached-input price); otherwise unknown (null). The library never
     guesses a price for an unknown model. The result says which source
     each cost came from.
116. The result carries: the data (a PHP array); the route that succeeded
     (provider name, requested model, reported model); the output mode
     used and why; every attempt in order (route, outcome, HTTP status,
     duration in milliseconds, tokens, cost, response id, request id,
     error excerpt); every skipped route and why (cooldown, hold,
     account-level in this call); token totals and cost totals for the
     successful attempt and for the whole call; the guard report (rules
     run, repairs, rejections on earlier attempts, regeneration); the
     credit-exhausted providers seen; any store error; total duration;
     the purpose. The raw content of the successful attempt is kept only
     when the caller asks.
117. An attempt event fires after every attempt, success or failure, with
     the same attempt record, so the caller can write usage rows. A
     listener that throws does not break the call: the exception is logged
     and recorded on the result.
118. Events: attempt finished, retry scheduled, route skipped, cooldown
     started, credit exhausted, guard repaired, guard rejected, chain
     exhausted. Listeners are plain callables.

### 4.13 Logging

119. With a PSR-3 logger: debug for each attempt; info for a fallback to a
     later route; warning for a retry, a cooldown start, a guard repair, a
     store error; error for a chain failure; critical for credit
     exhaustion. Every line has a context array with purpose, provider
     name, model, attempt number, outcome and HTTP status.
120. Credit exhaustion and store errors are logged at most once per
     provider (or store) per throttle window (default 600 seconds), using
     the store. The event still fires every time.
121. Without a logger, nothing is written anywhere. The library never calls
     `error_log` and never writes to stdout or stderr.
122. Logs never contain the API key, the Authorization header, the prompt
     or the output text, unless the caller turns on excerpt logging, which
     adds the first 200 characters of the output to failure lines.

### 4.14 Failure modes summary

| Failure | What happens | Open or closed | Why |
|---|---|---|---|
| Bad configuration | Exception before any request | Closed | A wrong setup must be loud on the first call. |
| Transport error | Retry, then next route | Closed per route | Blips are common and short. |
| Rate limit | Backoff retry, then next route | Closed per route | Usually clears in seconds. |
| Credit exhausted | Skip provider, event, critical log, hold | Closed per provider | Account condition, needs a human. |
| Auth failure | Skip provider | Closed per provider | Account condition. |
| Bad output | Retry, then next route, counts to cooldown | Closed | Never return unvalidated data. |
| Guard repair | Return repaired data with report | Open, with record | The caller chose repair for this field. |
| Store error | Proceed as if nothing cooled | Open | The store is only an optimisation. |
| Listener error | Log and continue | Open | The caller's bookkeeping must not lose a paid answer. |
| Custom guard rule throws | Throw to caller | Closed | It is a bug in the caller's code. |
| Deadline reached | Throw with attempts so far | Closed | Callers have execution limits. |

## 5. Public interface

Names below are the public contract for 0.1.0. Signatures are described in
prose; the builder chooses parameter order and exact types within these
rules, and records them in `docs/ARCHITECTURE.md`.

### 5.1 Classes

| Class | Role |
|---|---|
| `Client` | Entry point. Holds the chain, store, transport, logger, guard profiles, price table and options. Main method takes a request and returns a result. Also offers status and cooldown reset (case 112). |
| `Request` | Immutable. Purpose, system instruction, user input (or a message list), schema, and optional per-call overrides: chain, token cap, temperature, timeouts, deadline, guard profile, keep-raw flag, strict-credit flag. |
| `Schema` | Immutable. Name, JSON schema as a PHP array, and a strict-compatibility check that returns the first offending path or none. |
| `Provider` | Immutable. Name, kind, base URL, API key, extra headers, token cap parameter name, credit patterns, keyless and plain-HTTP flags. Named constructors for OpenRouter, OpenAI and generic. |
| `Route` | Immutable. Provider, model id, output mode (`auto`, `strict`, `json`, `prompt`), attempts, token cap, temperature, reasoning switch, upstream host preferences, extra body fields, timeouts. |
| `Chain` | Ordered, non-empty list of routes. |
| `GuardProfile` | Immutable. Rules with actions and paths, word floors, joining-script list, abbreviation list, custom callables, quote-instruction flag. |
| `Result` | Read-only. Everything in case 116. |
| `Attempt` | Read-only record of one attempt. |
| `Usage` | Token counts (nullable) and cost with its source. |
| `PriceTable` | Model id to input, output and optional cached-input price per million tokens. |
| `Options` | Retry base and cap, cooldown threshold, window and duration, hold time, deadline, minimum useful time, body size cap, store key prefix, log throttle, reliability switch. |

### 5.2 Interfaces and bundled implementations

| Interface | Bundled implementations |
|---|---|
| `Transport` | `CurlTransport` (default), `WordPressTransport` |
| `Store` (get, set with TTL, delete, increment with TTL, add-if-absent with TTL) | `MemoryStore`, `FileStore`, `Psr16Store`, `WordPressTransientStore` |
| `Clock` | System clock; a frozen clock for tests |
| `Sleeper` | Real sleeper; a recording sleeper for tests |
| Logger | Any PSR-3 logger, detected at runtime; nothing when absent |

The library ships test doubles (`MockTransport` with a queue of canned
responses, frozen clock, recording sleeper) in the main package under a
`Testing` sub-namespace, so users can test their own code without network.

### 5.3 Exceptions

All extend one base exception, `LlmJsonException`.

| Exception | When | Carries |
|---|---|---|
| `ConfigurationException` | Cases 1 to 10, 21, 22, 24, 27, 64, 65; a custom guard rule that throws | Message naming the field or path |
| `ChainExhaustedException` | Case 93, 97, 98 | All attempts, all skips, last error, reason (failures, deadline, all refused) |
| `CreditExhaustedException` (extends `ChainExhaustedException`) | Cases 93 and 94 | Providers exhausted, HTTP status and redacted body excerpt for each, the result when one exists (strict credit mode) |

Per-attempt errors are typed too, and appear as the `previous` chain and on
each `Attempt`: `TransportException`, `HttpException` (status, excerpt),
`RateLimitedException`, `AuthenticationException`,
`CreditExhaustedAttemptException`, `RefusalException`,
`ContentFilterException`, `InvalidResponseException`,
`MalformedOutputException`, `TruncatedOutputException`,
`SchemaViolationException` (path), `GuardRejectedException` (rule, path).
Only the three in the table reach the caller from the main method.

### 5.4 Without Composer

A single `bootstrap.php` at the package root registers a PSR-4 autoloader
for the namespace. One `require` of it is all a WordPress plugin needs.

## 6. Data

- **Stored by the library**: per-route failure counters, cooldown markers,
  provider credit holds, the per-day chain failure counter, and log throttle
  markers. Nothing else. No prompts, outputs, keys or usage.
- **Where**: the store the caller chose. Memory by default, so a client
  built with no store keeps nothing beyond the process.
- **Retention**: every entry has a TTL: counters 1800 seconds, cooldowns
  600, holds 60, throttle markers 600, the day counter 8 days. Expired
  entries disappear by TTL; the file store also sweeps (case 106).
- **Uninstall**: nothing to do for memory and PSR-16 stores beyond their
  TTLs. The file store's directory may be removed when the library is no
  longer used. The WordPress store's transients expire on their own; the
  README shows how a plugin's uninstall routine clears them by prefix.
- **Personal data**: the library holds none. Prompts and outputs pass
  through memory only. Callers that send personal data to a model are
  responsible for that processing.

## 7. Configuration and defaults

| Setting | Default | Notes |
|---|---|---|
| Output token cap | 8192 | Always sent. Size it near four times your largest real output. |
| Output mode | `auto` | Strict where the route supports it, else JSON mode, else prompt. |
| Attempts per route | 2 | One retry. |
| Retry base delay | 2 s | First retry waits 2 to 4 s. |
| Retry delay cap | 30 s | Also the most `Retry-After` the library will wait. |
| Connect timeout | 10 s | |
| Request timeout | 90 s | Normal calls take seconds; a hung host must not stall a run. |
| Total deadline per call | 300 s | Lower it in web requests. |
| Minimum useful time | 5 s | No attempt starts with less left. |
| Cooldown threshold | 2 | Consecutive failed calls on one route. |
| Failure window | 1800 s | Counter expiry. |
| Cooldown | 600 s | |
| Credit hold | 60 s | 0 turns it off. |
| Log throttle | 600 s | Credit and store-error lines. |
| Body size cap | 8 MiB | |
| Store | memory | |
| Store key prefix | `llmjson` | |
| Require honoured parameters (OpenRouter) | on | |
| Usage in response (OpenRouter) | on | |
| Reasoning | provider default | Turn off per route to save output tokens. |
| Guard profile | default (G1 to G5, reject) | Per purpose. |
| Strict credit mode | off | When on, a fallback success still throws. |
| Keep raw output | off | |
| Excerpt logging | off | |
| Reliability layer | on | Off: one attempt per route, no store. |

## 8. Security and privacy

- API keys are given to the provider object only. They are never logged,
  never put in exceptions, never stored. Store keys use a short hash of the
  key, not the key. Error bodies are scanned for anything shaped like a key
  or bearer token and redacted before they are kept.
- TLS verification is always on in the curl transport. There is no switch
  to turn it off. Plain HTTP is allowed only for loopback hosts or with an
  explicit provider flag.
- Redirects are never followed (case 24).
- Response bodies are size-capped (case 25) and JSON depth-capped (case 46).
- No `eval`, no shell, no `unserialize`, no dynamic includes. The file
  store writes only JSON, only to hashed file names, only inside the
  directory the caller named.
- Prompts and outputs are not logged by default.
- The guard is a quality check, not a security boundary. It does not
  sanitise HTML or defend against prompt injection. Callers must escape
  output for wherever they put it. The README says so plainly.
- Error suppression with `@` is not used anywhere.

## 9. Performance budget

- Library overhead per attempt, excluding network time: under 2 ms for a
  20 KB output on a typical server, including decode, validation and guard.
- Validation and guard are linear in output size. No regular expression
  with nested quantifiers runs on model output.
- Memory: under three times the response body size at peak.
- Store traffic: at most 4 store operations per attempt (hold check,
  cooldown check, counter update, log marker), none on the memory store
  path beyond array access.
- No network traffic other than the configured endpoints.
- Loading the library (autoload of the classes used in a call) under 1 ms
  with opcache.

## 10. Compatibility

- PHP 8.1, 8.2 and 8.3 tested in CI; 8.4 expected to work.
- Extensions: json (always present). PCRE with Unicode support (always
  present). ext-curl for the default transport only. No mbstring
  requirement: Unicode handling uses PCRE with the `u` modifier.
- Optional: `psr/log` (logger type), `psr/simple-cache` (PSR-16 store).
  Listed under Composer `suggest`, not `require`.
- WordPress 6.4 or later for the WordPress transport and store. The rest of
  the library does not need WordPress.
- Hosts: shared hosting with execution limits (use the deadline), CLI cron,
  long-running workers. Works behind HTTP proxies through curl's standard
  environment settings or WordPress proxy constants.
- Providers: OpenRouter, OpenAI, and any server that implements chat
  completions. Servers without response format support work in `prompt`
  mode.
- Caches: object caches that evict transients early make cooldowns
  best-effort (case 109).

## 11. Observability

- The result explains every call: which routes were tried and skipped, why,
  how long each took, what each cost, and what the guard did.
- Events (case 118) let the caller write usage rows, push metrics or page
  someone on credit exhaustion.
- PSR-3 log lines (case 119) at sensible levels, throttled where a storm is
  likely.
- Status (case 112) shows cooldowns, holds and today's chain failures, for
  a health page or a CLI command.
- The README has a short "is it working?" section: how to read a result,
  how to wire the credit event to an alert, and how to see cooldowns.

## 12. Acceptance tests

All tests run offline with the mock transport, frozen clock and recording
sleeper unless marked live. Each test names the cases it covers.

### 12.1 Configuration

- T1. Empty chain throws a configuration exception and sends nothing. (1)
- T2. Provider with empty key throws; keyless generic provider is
  accepted. (2)
- T3. Plain HTTP base URL throws; loopback and flagged providers are
  accepted. (3)
- T4. Schema with array root throws. (4)
- T5. Schema names that are empty, too long or contain spaces throw. (5)
- T6. Strict route with a non-strict schema throws and names the path; an
  auto route with the same schema uses JSON mode and records why. (6)
- T7. Temperature 2.5 and token cap 0 throw. (7)
- T8. Purpose with a slash throws. (8)
- T9. Both instruction and input empty throws; either alone is fine. (9)
- T10. Guard profile with a path absent from the schema throws. (10)

### 12.2 Request body

- T11. Every attempt body has the token cap; OpenAI uses the completion
  token parameter name, OpenRouter the other. (11)
- T12. Strict mode body carries the schema with strict true. (12)
- T13. JSON mode sends JSON object format and adds the schema instruction. (13)
- T14. Prompt mode sends no response format and adds the instruction. (14)
- T15. OpenRouter body asks for usage and honoured parameters; both can be
  turned off. (15)
- T16. Upstream host preferences pass through unchanged. (16)
- T17. Reasoning off produces the right field per provider kind. (17)
- T18. Temperature absent unless set. (18)
- T19. Attribution headers present only when configured; User-Agent always
  names the library. (19)
- T20. Quote instruction appended only when the profile enables it. (20, 76)
- T21. Extra body fields merge; overwriting the model or token cap throws. (21)
- T22. Invalid UTF-8 in the input throws before any request. (22)

### 12.3 Transport

- T23. Curl transport sets the connect and request timeouts, TLS checks on
  and redirects off (inspected through options, plus one local server
  test). (23)
- T24. A 301 response is not followed, not retried, and recorded. (24)
- T25. A body over the cap is aborted and recorded as invalid, then
  retried. (25)
- T26. A timeout is retried; a TLS verification failure is not. (26)
- T27. Missing curl without another transport throws with the install
  hint. (27)
- T28. WordPress transport maps `WP_Error` to a transport failure, reads
  status and body, and sets no redirection (with stub functions). (28)
- T29. A transport throwing a foreign exception is wrapped. (29)

### 12.4 Response shapes

- T30. Good OpenAI-shaped and OpenRouter-shaped bodies return the data. (30)
- T31. Fenced content with and without a json tag is unwrapped once and the
  unwrap recorded; a doubly fenced body is malformed. (31)
- T32. Leading whitespace before the body is accepted. (32)
- T33. Content as a list of text parts is joined. (33)
- T34. Refusal moves to the next route; all-refusal chain carries the flag. (34, 98)
- T35. Tool calls with null content are invalid and retried. (35)
- T36. Finish reason length with parseable content is still rejected and
  retried. (36, 66)
- T37. Content filter moves to the next route without retry. (37)
- T38. Empty choices are retried. (38)
- T39. 200 with a top-level error object is retried; with code 402 inside
  it is credit exhaustion. (39)
- T40. 200 with an error inside the first choice behaves the same. (40)
- T41. Empty body, HTML body and invalid UTF-8 body are each retried. (41)
- T42. Non-JSON content is retried and the excerpt is 200 characters. (42)
- T43. Content that is a JSON array is a schema violation. (43)
- T44. Duplicate keys: last wins. (44)
- T45. An integer beyond the PHP range fails an integer field. (45)
- T46. Nesting of 65 levels is malformed. (46)
- T47. Missing usage yields null tokens and null cost, not zero. (47)
- T48. Reported model differing from requested is recorded; cooldown keys
  use the requested one. (48)
- T49. Response id and request id header are on the attempt. (49)
- T50. Retry-After in seconds and as an HTTP date are both honoured. (50, 86)

### 12.5 Status handling

- T51. 400, 404, 409, 413 and 422 each move to the next route without
  retry and count towards cooldown. (51)
- T52. In auto mode, a 400 mentioning the response format retries once in
  JSON mode and later calls on that route start in JSON mode. (51)
- T53. 401 and 403 skip the rest of that provider, do not count, and the
  next provider is tried. (52)
- T54. 402 is credit exhausted. (53)
- T55. 429 with each built-in credit pattern is credit exhausted; a custom
  pattern added by the caller is honoured. (54)
- T56. 403 with a billing pattern is credit exhausted, not auth. (55)
- T57. Plain 429 retries after backoff; Retry-After above the cap skips the
  retry. (56)
- T58. 408, 425, 500, 502, 503, 504 and 522 are each retried. (57)
- T59. 418 moves on without retry and counts. (58)
- T60. A key-shaped string in an error body is redacted in the excerpt. (59)

### 12.6 Schema validation

- T61. Each supported keyword accepts a valid and rejects an invalid
  value, including nullable type lists and code-point string length. (60)
- T62. Missing nested required key names the full path. (61)
- T63. Wrong type, out-of-enum value and extra key each fail. (62)
- T64. An unsupported keyword is ignored and logged once at debug. (63)
- T65. An invalid pattern throws a configuration exception. (64)
- T66. A cyclic reference beyond depth 32 throws. (65)
- T67. Strict mode output missing a required key is still rejected (the
  provider is not trusted). (61, 67)

### 12.7 Guard

- T68. Each character in the G3 reject list, at the start, middle and end
  of a value and in a key, is rejected. (68)
- T69. Joiners inside Devanagari and Malayalam words and in emoji
  sequences pass; between Latin letters they fail; repair removes them and
  reports the count. (69)
- T70. C0, DEL and C1 controls fail; tab and newline pass unless the field
  is single-line; repair removes them. (70)
- T71. U+FFFD fails and cannot be repaired. (71)
- T72. Sentence ends: each listed terminator passes, with and without
  closing quotes and brackets; a value ending in a word fails; markup and
  entities are stripped first; empty string passes. (72)
- T73. Repair cuts to the last sentence; an abbreviation (Dr., U.S.) is not
  treated as an end; a value with no sentence becomes empty and an empty
  paragraph is removed; nothing is cut mid-sentence. (73)
- T74. A later paragraph starting in lower case is rejected or removed;
  the first paragraph is never removed for this; caseless scripts pass. (74)
- T75. Under-floor after repair triggers one regeneration; the result with
  more words is kept; a failed regeneration keeps the first; the result
  records it. (75)
- T76. Word counting gives sensible counts for English, Hindi and a
  script without spaces. (75)
- T77. Script share rule passes a mostly Devanagari body and rejects an
  English body under a Hindi title. (77)
- T78. A word mixing Latin and Cyrillic letters is rejected when the rule
  is on and passes when it is off. (78)
- T79. A custom rule's violations reject; a custom rule that throws reaches
  the caller as a configuration exception. (79)
- T80. A guard rejection is retried and counts towards cooldown. (80)
- T81. The repair report lists rule, path and counts; the original data is
  present only when keep-raw is on. (81)
- T82. The default profile applies G1 to G5 and nothing else. (66 to 71)
- T83. The corruption fixture: a realistic paragraph with a zero width
  space inserted mid-word and a soft hyphen is rejected by the default
  profile. (68)
- T84. The broken-quote fixture: a paragraph cut at an opening quote plus
  a lower-case spill-over item is repaired to complete sentences only. (72
  to 74)

### 12.8 Retries and chain

- T85. Two attempts per route by default; the retry goes to the same
  route. (82)
- T86. Every retryable outcome in case 83 retries; every outcome in case 84
  does not. (83, 84)
- T87. Delays fall within the documented ranges for retries 1, 2 and 3 and
  never exceed the cap. (85)
- T88. The recording sleeper sees every delay and no real time passes. (87)
- T89. Success on the second route ends the call and records the first
  route's attempts. (88)
- T90. After credit exhaustion on provider A, later routes on A are
  skipped and recorded; a route on provider B is tried. (89)
- T91. A cooled route is skipped and recorded. (90)
- T92. With every route cooled, the last route is still attempted; with
  every provider held, the last route is still attempted. (91)
- T93. A transport failure on the primary leads to the sibling model on
  the same provider, not straight to another provider. (92)
- T94. A full failure with no credit issue throws chain exhausted; with a
  credit issue it throws credit exhausted, which is caught by a
  chain-exhausted catch. (93)
- T95. Credit exhaustion followed by success returns the result, lists
  the provider, fires the event and logs one critical line; strict credit
  mode throws with the result attached. (94)
- T96. A full failure increments today's counter in the store. (95)
- T97. A per-call chain override is used without changing the client. (96)
- T98. The deadline stops new attempts, clips timeouts, and the exception
  says deadline. (97)

### 12.9 Cooldowns, holds and stores

- T99. Credit exhaustion sets a hold; another call within 60 seconds skips
  that provider; after 61 seconds it is tried; hold 0 disables it. (99)
- T100. One failed call sets the counter to 1; it expires after the
  window. (100)
- T101. Two consecutive failed calls start a 600 second cooldown, clear the
  counter, and log and fire the event exactly once across many skips. (101)
- T102. A success clears the counter; a success on a cooled last route
  ends its cooldown. (102)
- T103. 401, 402, 403 and credit-pattern 429 leave the counter untouched. (103)
- T104. Store keys are short, contain no key material, and differ for two
  keys on the same provider. (104)
- T105. Concurrency: two processes (forked with pcntl when available, else
  two sequential clients on one file store with interleaved operations)
  incrementing the file store never corrupt it, and add-if-absent lets
  exactly one win. (105, 106)
- T106. File store: modes 0700 and 0600, atomic replace, expired entries
  gone on read, sweep bounded at 50 files. (106)
- T107. Memory store contract tests. (107)
- T108. PSR-16 store contract tests against a simple array cache, skipped
  when the interface is absent. (108)
- T109. WordPress store contract tests with stub transient functions, and
  keys within the length limit. (109)
- T110. A store that throws on every call: the request still succeeds, a
  throttled warning is logged, and the result carries the store error. (110)
- T111. Moving the frozen clock expires counters, cooldowns and holds. (111)
- T112. Status reports counters, cooldowns, holds and today's failures;
  reset clears one route and all routes. (112)
- T113. Reliability off: one attempt per route, zero store operations,
  token cap and guard still applied. (113)

### 12.10 Accounting, events and logging

- T114. Usage from a rejected but billed attempt is counted in call
  totals; reasoning and cached tokens are read when present. (114)
- T115. Reported cost wins over the price table; the table is used when no
  cost is reported; an unknown model gives null cost; the source is
  recorded. (115)
- T116. The result carries every field in case 116, checked on a three-route
  call with one skip, one retry and one success. (116)
- T117. The attempt event fires once per attempt; a throwing listener is
  logged and recorded but the result is returned. (117)
- T118. Each event fires at the documented moment. (118)
- T119. Log levels and context keys match case 119. (119)
- T120. Ten credit failures within the throttle window give one critical
  line and ten events. (120)
- T121. With no logger, nothing reaches the PHP error log or the output
  streams (checked by capturing both). (121)
- T122. A log capture of a failing call contains no key, no Authorization
  value, no prompt text; with excerpt logging on, it contains at most 200
  characters of output. (122)

### 12.11 Packaging and live checks

- T123. `bootstrap.php` alone autoloads every class used in the quick
  start, without Composer. (5.4)
- T124. The package installs with no runtime dependencies (`composer
  show --tree` on a fresh project lists none).
- T125. Live, opt-in, skipped in CI unless keys are present: one strict
  call each to OpenRouter and OpenAI returns valid data, a usage block, and
  a cost (reported or from a given table).

## 13. Open questions

The builder answers each in `docs/ARCHITECTURE.md`.

- Q1. Should a later version add the OpenAI Responses API as a second wire
  format? It has a different request and response shape and its own
  refusal field. 0.1.0 uses chat completions on every provider, which
  OpenAI supports with strict schemas.
- Q2. How should the client accept a PSR-3 logger without requiring
  `psr/log`? Options: accept any object and check for the interface at
  runtime, or ship a tiny internal logger interface plus an adapter.
- Q3. Is 60 seconds the right default credit hold? Long enough to stop a
  storm of doomed requests from many workers, short enough that a top-up
  takes effect almost at once. The material behind this PRD never used a
  hold (each call re-tried the exhausted provider and only the log line
  was throttled), so this default has no production evidence yet.
- Q4. Should `auto` mode learn per route that strict mode is unsupported
  (case 51) only for the process, or persist it in the store?
- Q5. Should the WordPress store use site transients on multisite?
- Q6. Should the abbreviation list for sentence repair be per language,
  and which languages ship in 0.1.0 beyond English?
- Q7. Is a minimal pure-PHP transport (streams) worth shipping for hosts
  without curl, given that WordPress hosts already have the WordPress
  transport?
- Q8. The 8192 default token cap suits prose. Should the default scale
  with the schema (for example, smaller for schemas with only enums and
  short strings)?

## 14. Lessons from production

Each lesson is written as a rule the library follows.

1. **Always send an output token cap.** A request with no cap made a router
   reserve credit against the model's full default ceiling, and a modest
   balance answered 402 although real use was a fraction of it. A cap of
   about four times the largest real output removed those errors and never
   truncated real work. (Cases 11, 53.)
2. **Retry once on the same model before moving on.** Truncated and
   malformed responses are frequent and usually transient. One retry after
   a short sleep turned a mid-key truncation into a success ten seconds
   later, instead of a jump to a costlier provider. (Cases 82 to 85.)
3. **Never retry what cannot change.** A bad request, a bad key and an
   empty balance give the same answer four seconds later. Retrying them
   only wastes time and money. (Case 84.)
4. **Account conditions do not cool models.** An empty balance or a bad
   key is shared by every model on the provider. Cooling a model for it
   only pushes the same error to the next model and then leaves good
   models parked after the balance is fixed. (Case 103.)
5. **There is always a path.** Cooldowns and holds must never leave a call
   with nothing to try. The last route is always attempted. (Case 91.)
6. **A blip on one model is not a verdict on the provider.** Breaking the
   whole chain on one transport error sent work straight to the
   last-resort provider at several times the cost while the sibling model
   was fine. (Case 92.)
7. **Credit exhaustion is its own signal.** One provider says 402; another
   says 429 with a quota code in the body, which looks like a rate limit.
   Both need the same response: skip to another provider at once, and tell
   a human loudly. When an account ran dry with no fallback and no alert,
   everything downstream stopped for hours. (Cases 53 to 55, 89, 94, 99,
   120.)
8. **Validate even when the provider promises strict output.** Routers send
   requests to many upstream hosts, and not all of them enforce the
   schema. Check every required key yourself, and ask the router to use
   only hosts that honour the parameters you sent. (Cases 15, 61, 67.)
9. **A 200 is not a success.** Routers return HTTP 200 with an error
   object when an upstream host fails mid-route, and return 200 with empty
   or cut-off content. Classify the body, not the status. (Cases 36 to 41.)
10. **Valid JSON can still be broken prose.** Under a JSON grammar a
    straight double quote ends the string, so the field closes mid-sentence
    and the rest spills into an extra item that starts in lower case.
    Prevent it with a prompt rule, detect it with the sentence-end check,
    and drop continuation items. This is not caused by the token cap: a
    capped completion is invalid JSON and is caught separately. (Cases 72
    to 76.)
11. **Never cut mid-sentence when repairing.** Cut back to the last
    complete sentence, respect abbreviations and non-Latin terminators such
    as the danda, and if repair leaves too little, regenerate once and keep
    the better result. (Cases 73, 75.)
12. **Reject invisible characters by default.** A third-party AI writing
    plugin silently inserted typos and zero-width characters into
    generated text, and nobody saw it until it was published. Any string
    carrying zero-width, bidirectional, control or replacement characters
    is rejected unless a profile says otherwise. Joiners are allowed only
    where scripts need them, so Indic and Arabic text is not harmed.
    (Cases 68 to 71.)
13. **Prefer one layer you control between the model and the page.** Any
    tool that rewrites model output after the call can corrupt it. The
    guard runs last, on the exact data the caller will use, and the README
    says to keep it last. (Cases 66 to 81.)
14. **Check the script, not just the shape.** A translation came back with
    a translated title over an untranslated body, and the schema was
    satisfied. A script-share rule catches it. (Case 77.)
15. **Turn off hidden reasoning when it is not wanted.** Some models bill
    hidden reasoning tokens as output by default, and those tokens also
    eat the cap. (Case 17.)
16. **Bill failed attempts.** A rejected answer was still paid for.
    Accounting must count every billed attempt, or spend reports lie.
    (Case 114.)
17. **Never guess a price.** A fallback price for unknown models produced
    numbers that looked real and were not. Report reported cost, or the
    caller's price, or unknown. (Case 115.)
18. **Do not trust the error log to be read.** Under cron, the PHP error
    log went nowhere, and retries and guard actions left no trace. Put the
    story of every call on the result, emit events, and log through the
    caller's logger. (Cases 116 to 122.)
19. **Throttle alarms, not events.** One log line per provider per ten
    minutes keeps a credit storm readable; the event still fires every
    time so metrics stay true. (Case 120.)
20. **Keep observability counters out of the decision path.** A daily
    chain-failure counter is useful to watch; nothing should halt on it.
    Test harnesses that force failures will pollute it, so tests use their
    own store. (Case 95.)
21. **Give the reliability layer an off switch.** When the retry and
    cooldown logic itself is suspected, one switch must restore plain
    one-attempt-per-route behaviour, and the switch must have been tested.
    (Case 113.)
22. **Bound every wait.** A hung host must not stall a run. Use a connect
    timeout, a request timeout, and a total deadline that knows the
    caller's execution limit. (Cases 23, 97.)
23. **Log a cooldown once, at the start.** A line per skipped call buries
    the one line that matters. (Case 101.)
24. **Fail loud on configuration, quiet on optimisation.** A typo in a
    schema path or a guard path silently turned checks off; such mistakes
    throw on the first call. A cache outage, by contrast, must never stop
    model calls. (Cases 10, 110.)
