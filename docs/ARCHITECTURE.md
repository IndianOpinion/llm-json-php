# Architecture

This document describes how llm-json-php 0.1.0 is built, how data flows
through one call, and every design decision, including the answers to the
open questions in `docs/PRD.md` section 13. Case numbers refer to the PRD.

## Modules

| Namespace | Contents | Role |
|---|---|---|
| `IndianOpinion\LlmJson` | `Client`, `Request`, `Schema`, `Provider`, `Route`, `Chain`, `Options`, `PriceTable`, `Result`, `Attempt`, `Usage`, `Skip`, `Status`, `RouteStatus`, `Event`, event payloads, enums `OutputMode`, `ProviderKind`, `Outcome`, `CostSource` | Public API |
| `...\Exception` | `LlmJsonException` (base), `ConfigurationException`, `ChainExhaustedException`, `CreditExhaustedException`, `StoreException`, and the per-attempt `AttemptException` family | Typed errors |
| `...\Transport` | `Transport`, `HttpRequest`, `HttpResponse`, `CurlTransport`, `StreamTransport`, `WordPressTransport`, `TransportFactory` | HTTP |
| `...\Store` | `Store`, `MemoryStore`, `FileStore`, `Psr16Store`, `WordPressTransientStore` | Cooldowns, holds, counters |
| `...\Validation` | `Validator` | Strict structured output keywords |
| `...\Guard` | `GuardProfile`, `Guard`, `TextRules`, `Action`, `Rule`, `Violation`, `Repair`, `GuardReport`, `Paths` | Output quality checks |
| `...\Log` | `Logger`, `NullLogger`, `Psr3Logger` | Logging without a dependency |
| `...\Clock`, `...\Sleeper` | `Clock`, `SystemClock`, `Sleeper`, `RealSleeper` | Injectable time |
| `...\Testing` | `MockTransport`, `FrozenClock`, `RecordingSleeper`, `RecordingLogger` | Test doubles shipped for users |
| `...\Internal` | `BodyBuilder`, `ResponseReader`, `Json`, `Text`, `Redactor`, `Backoff`, `Keys`, `CallState`, `ProcessMemory`, `ErrorTrap` | Not public API; may change in any release |

Every value object is immutable. `with*` methods return a copy. `Client`
is immutable too; its copies share one `ProcessMemory` (learned downgrades,
in-process log throttles) and, unless replaced, one store.

## Data flow of one call

```
Client::complete(Request)
  1. validate: content present, chain present, guard paths in schema,
     strict routes have strict-compatible schemas      (cases 1 to 10)
  2. for each route in the chain                       (4.10)
       skip: account failure in this call, credit hold, cooldown
             (never the last route for hold or cooldown, case 91)
       resolve output mode (auto: strict, json or prompt)
       loop attempts:
         check deadline, clip timeouts                (case 97)
         BodyBuilder -> HttpRequest -> Transport::send
         ResponseReader classifies into one Outcome   (4.5, 4.6)
         on a good 200: unwrap fence, decode (depth 64),
           Validator (4.7), Guard (4.8)
         price the usage, record Attempt, fire attempt.finished
         success -> clear failure counter, return
         credit -> event, hold, throttled critical log
         auto + strict rejected -> retry once in json, remember
         account level -> mark provider, next route
         retryable -> backoff sleep, retry same route
       route failed -> failure counter, maybe cooldown
  3. all routes failed -> day counter, chain.exhausted, throw
  4. guard shortfall under a word floor -> one regeneration, keep the
     result with more words                           (case 75)
  5. build Result; strict credit mode may throw with the result attached
```

## Store keys and values

Keys are `<prefix>.<type>.<hash>`, with prefix `llmjson` by default. They
are at most 51 characters, ASCII, valid as PSR-16 keys and transient names.

| Type | Hash of | Value | TTL |
|---|---|---|---|
| `f` | route (base URL, key fingerprint, requested model) | consecutive failed calls | failure window, 1800 s |
| `c` | route | cooldown end, Unix seconds | cooldown, 600 s |
| `d` | route | downgrade end, Unix seconds | downgrade memory, 86400 s |
| `h` | provider (base URL, key fingerprint) | hold end, Unix seconds | credit hold, 60 s |
| `t` | provider | 1, credit log throttle marker | log throttle, 600 s |
| `n` | UTC date `Ymd` | chain failures that day | 8 days |

The key fingerprint is the first 12 hex characters of a SHA-256 of the key
with a fixed prefix. No key material is ever stored.

## Decisions on the PRD's open questions

1. **Credit hold (Q3).** The hold defaults to 60 seconds and is set with
   `Options::withCreditHold()`; 0 turns it off. The figure is a design
   choice, not a measured one: it is long enough to stop a storm of doomed
   requests from many workers and short enough that a top-up takes effect
   within a minute. The production material behind the PRD never used a
   hold, so there is no evidence for this number yet.
2. **Responses API (Q1).** 0.1.0 speaks chat completions only, on every
   provider. OpenAI supports strict schemas there. The Responses API is
   listed under "Not yet" in the README and the CHANGELOG.
3. **Logging without psr/log (Q2).** The library defines a tiny
   `Log\Logger` interface with PSR-3 level names. `Log\Psr3Logger` adapts
   any PSR-3 logger and checks at runtime that `Psr\Log\LoggerInterface`
   exists. `Client::withLogger()` accepts either and wraps a PSR-3 logger
   for you. No dependency is added; `psr/log` is in `suggest`.
4. **Strict-to-JSON downgrade (Q4).** In auto mode, a 400 or 422 whose body
   mentions the response format makes the same route retry once in JSON
   mode. The downgrade is written to the same store as cooldowns (`d` key,
   TTL `Options::withDowngradeMemory()`, default 86400 seconds), so other
   processes start in JSON mode too. It is also kept in process memory.
   With the reliability layer off, it is kept in process memory only. The
   extra JSON attempt does not use up the route's retry budget.
5. **WordPress store and multisite (Q5).** `WordPressTransientStore` uses
   site transients when `is_multisite()` is true, plain transients
   otherwise. Pass `network: true|false` to override. With a persistent
   object cache, add-if-absent uses `wp_cache_add()` in the transient group
   and is atomic.
6. **Abbreviations (Q6).** English only ships
   (`GuardProfile::ENGLISH_ABBREVIATIONS`). Callers extend or replace it
   with `withAbbreviations()`. Initials such as `U.` and dotted forms such
   as `e.g.` are recognised by shape for every language.
7. **Streams transport (Q7).** `StreamTransport` ships as the fallback when
   ext-curl is missing. `Client::create()` picks curl, else streams (needs
   `allow_url_fopen` and ext-openssl), else throws a configuration
   exception that names ext-curl and `WordPressTransport`. This replaces
   the PRD's case 27 behaviour of throwing whenever curl is missing.
8. **Token cap (Q8).** A fixed default of 8192, overridable per route
   (`Route::withTokenCap()`), per call (`Request::withTokenCap()`) or
   globally (`Options::withDefaultTokenCap()`). It is not scaled by schema
   size.

## Other decisions the PRD left to the builder

- **Public method names.** `Client::complete(Request): Result` is the main
  method; `Client::ask()` is a shortcut that returns the data only.
  Listeners are added with `Client::on(Event::NAME, callable)` and receive
  one typed payload object (see `Event`).
- **Generic providers default to prompt mode.** A generic endpoint's
  structured output support is unknown, so auto mode picks prompt mode
  until `Provider::withStructuredOutputs()` says otherwise.
- **`format` and strict compatibility.** `format` is accepted in strict
  mode (providers allow it) but is not checked by the local validator, and
  is reported as an ignored keyword. Annotations (`description`, `title`,
  `default`, `examples`, `$comment`, `$schema`, `$id`) are accepted and not
  reported.
- **Integers.** Only PHP integers pass an `integer` type. A JSON `1.0`
  fails. This keeps case 45 strict.
- **Redirects.** A 3xx is recorded as outcome `redirect` with an
  `HttpException`. It is treated like an account-level failure: not
  retried, not counted towards cooldown, and the rest of that provider is
  skipped for the call. The call does not throw a configuration exception,
  so a later provider can still answer.
- **What counts towards cooldown.** Every non-success outcome except
  authentication, credit, redirect, refusal and content filter. Refusals
  and filters are about the prompt, not the route's health.
- **Object keys and the guard.** Invisible, control and replacement
  characters in object keys are always rejected, never repaired, because a
  renamed key could collide with another.
- **Single-line repair.** In single-line fields, tabs and line breaks are
  replaced by one space rather than removed, so words do not merge.
- **G6 repair and markup.** Repair cuts the raw string at the end of the
  last complete sentence, keeping closing tags that follow it. An opening
  tag whose closing tag was cut stays unbalanced; balancing markup is the
  caller's job.
- **Word count.** Runs of letters, marks and digits are words; each Han,
  Hiragana or Katakana character counts as one word. Thai, Lao, Khmer and
  Burmese count a run as one word (a known limitation).
- **Store error log throttle.** Store errors are throttled in process
  memory, since the store is the thing that is failing.
- **WordPress timeouts.** WordPress has one timeout; the request timeout is
  passed as it. The stream transport also has no separate connect timeout.
- **OpenRouter usage flag.** OpenRouter now returns usage on every chat
  completion and documents `usage: {include: true}` as deprecated with no
  effect. The library still sends it by default, as the PRD asks, because
  it is harmless and some compatible routers still read it. Turn it off
  with `Route::withUsageReporting(false)`.
- **Reasoning off.** OpenRouter receives `reasoning: {enabled: false}`;
  OpenAI and generic providers receive `reasoning_effort` set to the value
  given to `Route::withoutReasoning()` (default `low`, the lowest value
  every reasoning model accepts; pass `minimal` or `none` where supported).
- **Retry-After.** Honoured on 429 and 5xx (including 503). Above the delay
  cap, the route is not retried and the next route is tried.
- **Deadline.** Checked before every attempt and before every sleep. A
  retry whose sleep would leave less than the minimum useful time is not
  started.
- **Day counter.** Incremented once per failed call, including deadline
  failures, never by a failed G8 regeneration (the call succeeded).
- **Regeneration.** Runs through the whole chain, with cooldowns and holds
  in force. Its attempts are on the result with phase `regeneration`.

## Error handling rules

- No `@` operator anywhere. File and stream functions run inside
  `Internal\ErrorTrap`, which turns warnings into messages.
- Configuration mistakes throw before network traffic where possible.
  A custom guard rule that throws, an invalid `$ref` chain deeper than 32,
  and an extra body field that overwrites an owned field throw
  `ConfigurationException` at call time.
- Store failures never stop a call (case 110). Listener failures never stop
  a call (case 117).
- Exceptions and log lines never carry the API key, the Authorization
  header, the prompt or the output. Output excerpts appear only on
  `MalformedOutputException::excerpt()` and, when excerpt logging is on, in
  failure log lines (200 characters).

## Testing approach

All acceptance tests run offline with `Testing\MockTransport`, a frozen
clock and a recording sleeper. Response fixtures in
`tests/Fixtures/responses/` follow the shapes the OpenAI and OpenRouter
documentation publish. Transport tests also run against PHP's built-in
server on a loopback port. `tests/Live` is opt-in (`LLMJSON_LIVE=1` plus
keys) and skipped in CI.
