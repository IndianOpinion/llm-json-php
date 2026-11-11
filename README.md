# llm-json-php

Structured JSON from OpenRouter, OpenAI or any chat completions endpoint, in
plain PHP with no dependencies. One call goes in; a PHP array that matches
your JSON schema comes out, or a typed exception that says exactly what went
wrong, after a model chain, retries, cooldowns, credit-exhausted fallback,
schema validation and a guard against truncated or corrupted text.

Measured in production: after a bounded same-model retry was added, the
first truncated response it met (cut off mid-key) was retried and succeeded
about ten seconds later, instead of falling to a costlier provider.

This project came out of the work on indianopinion.org and is written from
scratch; it is independent of and not affiliated with any third-party
plugin it integrates with.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [The guard](#the-guard)
- [Is it working?](#is-it-working)
- [WordPress](#wordpress)
- [Limitations](#limitations)
- [Not yet](#not-yet)
- [FAQ](#faq)
- [Licence](#licence)

## Install

PHP 8.1 or later. ext-json and PCRE (always present). ext-curl is
recommended; without it the library uses PHP streams.

With Composer:

```sh
composer require indianopinion/llm-json-php
```

Without Composer, copy the repository (or a release archive) into your
project and require one file:

```php
require __DIR__ . '/lib/llm-json-php/bootstrap.php';
```

## Quick start

```php
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\FileStore;

$schema = Schema::fromArray('summary', [
    'type' => 'object',
    'properties' => [
        'title' => ['type' => 'string'],
        'points' => ['type' => 'array', 'items' => ['type' => 'string']],
    ],
    'required' => ['title', 'points'],
    'additionalProperties' => false,
]);

$openRouter = Provider::openRouter((string) getenv('OPENROUTER_API_KEY'));
$client = Client::create(Chain::of(
    Route::to($openRouter, 'openai/gpt-4o-mini'),             // primary
    Route::to($openRouter, 'google/gemini-2.0-flash-001'),    // sibling
    Route::to(Provider::openAi((string) getenv('OPENAI_API_KEY')), 'gpt-4o-mini'), // last resort
))->withStore(new FileStore('/var/cache/my-app/llmjson'));

try {
    $result = $client->complete(
        Request::for('summary', $schema)
            ->withSystem('Summarise the article as a title and short points.')
            ->withInput($articleText)
    );
    $data = $result->data();      // validated PHP array
} catch (CreditExhaustedException $e) {
    // A provider is out of credit and nothing else answered. Page someone.
} catch (ChainExhaustedException $e) {
    // Every route failed. $e->attempts() says how.
}
```

Runnable samples are in [`examples/`](examples): `quickstart.php` and
`guard-profile.php` run offline; `live-openrouter.php` needs keys;
`wordpress-plugin.php` shows a plugin without Composer.

## Configuration

Client-wide settings live in `Options`; per-route settings on `Route`;
per-call overrides on `Request`.

| Setting | Default | Where | Notes |
|---|---|---|---|
| Output token cap | 8192 | `Options::withDefaultTokenCap()`, `Route::withTokenCap()`, `Request::withTokenCap()` | Always sent. Size it near four times your largest real output. |
| Output mode | `auto` | `Route::withMode()` | Strict where the provider supports it and the schema allows it, else JSON mode, else prompt. |
| Attempts per route | 2 | `Options::withAttemptsPerRoute()`, `Route::withAttempts()` | One retry. |
| Retry delay base and cap | 2 s, 30 s | `Options::withRetryDelays()` | First retry waits 2 to 4 s. The cap is also the most `Retry-After` the library waits. |
| Connect and request timeouts | 10 s, 90 s | `Options::withTimeouts()`, `Route::withTimeouts()`, `Request::withTimeouts()` | |
| Total deadline per call | 300 s | `Options::withDeadline()`, `Request::withDeadline()` | Lower it in web requests. |
| Minimum useful time | 5 s | `Options::withDeadline($s, $min)` | No attempt starts with less left. |
| Cooldown threshold, failure window, cooldown | 2, 1800 s, 600 s | `Options::withCooldown()` | Consecutive failed calls on one route. |
| Credit hold | 60 s | `Options::withCreditHold()` | 0 turns it off. A design choice, not a measured figure. |
| Downgrade memory | 86400 s | `Options::withDowngradeMemory()` | How long a learned strict-to-JSON downgrade lasts. |
| Log throttle | 600 s | `Options::withLogThrottle()` | Credit and store-error lines. |
| Body size cap | 8 MiB | `Options::withMaxResponseBytes()` | |
| Store | memory | `Client::withStore()` | `MemoryStore`, `FileStore`, `Psr16Store`, `WordPressTransientStore`. |
| Store key prefix | `llmjson` | `Options::withStoreKeyPrefix()` | |
| Require honoured parameters (OpenRouter) | on | `Route::withRequireParameters()` | |
| Usage in response (OpenRouter) | on | `Route::withUsageReporting()` | |
| Reasoning | provider default | `Route::withoutReasoning()` | Saves output tokens. |
| Upstream hosts (OpenRouter) | none | `Route::withHostPreferences()` | Passed through unchanged. |
| Guard profile | G1 to G5, reject | `Client::withGuardProfile($purpose, ...)`, `Request::withGuardProfile()` | Per purpose. |
| Strict credit mode | off | `Options::withStrictCredit()`, `Request::withStrictCredit()` | When on, a fallback success still throws. |
| Keep raw output | off | `Request::withKeepRaw()` | |
| Excerpt logging | off | `Options::withExcerptLogging()` | Adds 200 characters of output to failure lines. |
| Reliability layer | on | `Options::withReliability()` | Off: one attempt per route, no store traffic. The escape hatch. |
| Prices | none | `Client::withPriceTable()` | Per million tokens. Never guessed. |

Providers:

```php
Provider::openRouter($key, referer: 'https://example.org', title: 'My app');
Provider::openAi($key);
Provider::generic('local', 'http://localhost:11434/v1', keyless: true)
    ->withStructuredOutputs(strict: false, jsonObject: true);
```

## How it works

1. **Validate first.** Empty chains, empty keys, plain `http` to a remote
   host, a schema without an object root, a strict route with a schema that
   is not strict-compatible, a guard path that is not in the schema, bad
   temperatures and invalid UTF-8 all throw `ConfigurationException` before
   any request.
2. **Build the body.** Always with a token cap. Strict mode sends the schema
   with `strict: true`. JSON and prompt modes put the schema in the system
   message. On OpenRouter it asks for usage and for upstream hosts that
   honour every parameter.
3. **Read the response, not the status.** Each response becomes exactly one
   outcome: success, truncated (`finish_reason` length), malformed,
   schema violation, guard rejected, refusal, content filter, invalid
   response, a provider error inside a 200, rate limited, credit exhausted
   (402, or a quota pattern in a 429, 403 or 200 body), authentication
   failed, request rejected, server error, redirect.
4. **Retry what can change.** Transport errors, bad output, 408, 425, 429
   and 5xx retry on the same route after a jittered delay. Bad requests,
   bad keys, empty balances, refusals and redirects never do.
5. **Walk the chain.** A transport blip on one model moves to its sibling,
   not straight to another provider. A credit or key problem skips every
   route on that account for the call. Routes that keep failing go on
   cooldown, and a provider that ran out of credit is held briefly, but the
   last route is always tried.
6. **Validate and guard every output,** even in strict mode, because a
   router can send a request to a host that does not enforce the schema.
7. **Explain the call.** The `Result` lists every attempt, skip, token
   count, cost, guard action and credit problem.

## The guard

The guard runs on the parsed, schema-valid data, last, so it checks exactly
what you will use. Keep it last: any tool that rewrites model output after
the call can corrupt it.

| Rule | What it catches | Actions |
|---|---|---|
| G1 | Truncated JSON | always on |
| G2 | Missing required fields | always on |
| G3 | Zero-width, bidirectional, tag and filler characters; joiners outside Indic, Sinhala, Arabic and Syriac words and emoji | reject, repair |
| G4 | Control characters; tabs and line breaks in single-line fields | reject, repair |
| G5 | U+FFFD replacement character | reject |
| G6 | Text that ends mid-sentence | reject, repair (cut to the last whole sentence) |
| G7 | A later paragraph that starts in lower case (the tail of a sentence cut by a quote) | reject, repair (drop it) |
| G8 | Too few words left after repair | one regeneration, keep the longer |
| G9 | Asks the model not to type straight double quotes | prompt |
| G10 | Too few letters in the expected script | reject |
| G11 | A word mixing Latin with Cyrillic or Greek | reject |
| G12 | Your own callables | reject |

```php
use IndianOpinion\LlmJson\Guard\Action;
use IndianOpinion\LlmJson\Guard\GuardProfile;

$profile = GuardProfile::forSchema($schema)
    ->withQuoteInstruction()
    ->withControlCharacters(Action::Reject, ['/title'])
    ->withSentenceEnd(['/paragraphs'], Action::Repair)
    ->withContinuationItems(['/paragraphs'], Action::Repair)
    ->withWordFloor('/paragraphs', 80)
    ->withScriptShare('/paragraphs', 'Devanagari', 0.3);

$client = $client->withGuardProfile('translate-hi', $profile);
```

Paths are JSON pointers; `*` means every item of an array.

The guard is a quality check, not a security boundary. It does not
sanitise HTML or defend against prompt injection. Escape the output for
wherever you put it.

## Is it working?

Read the result:

```php
foreach ($result->attempts() as $a) {
    printf("%d %s/%s %s %s %dms\n", $a->number, $a->provider, $a->model,
        $a->outcome->value, $a->httpStatus ?? '-', $a->durationMs);
}
$result->skips();             // routes passed over, and why
$result->totalUsage()->cost;  // every billed attempt, failures included
$result->guardReport();       // repairs, earlier rejections, regeneration
```

Wire credit exhaustion to an alert. The event fires every time; the
critical log line is throttled:

```php
use IndianOpinion\LlmJson\CreditExhaustion;
use IndianOpinion\LlmJson\Event;

$client = $client
    ->withLogger($psr3Logger)
    ->on(Event::CREDIT_EXHAUSTED, fn (CreditExhaustion $e) => $pager->send("{$e->provider} is out of credit"))
    ->on(Event::ATTEMPT_FINISHED, fn ($attempt) => $usageTable->insert($attempt));
```

See cooldowns and today's chain failures, for a health page or a CLI:

```php
$status = $client->status();
foreach ($status->routes as $r) {
    echo "$r->provider/$r->model failures=$r->failureCount cooldown=" . ($r->cooldownUntil ?? '-') . "\n";
}
echo "chain failures today: $status->chainFailuresToday\n";
$client->resetCooldowns();
```

Without a logger the library writes nothing anywhere: no `error_log`, no
output.

## WordPress

```php
require_once __DIR__ . '/lib/llm-json-php/bootstrap.php';

$client = Client::create($chain, new WordPressTransport())
    ->withStore(new WordPressTransientStore());
```

`WordPressTransport` uses `wp_remote_post()` with redirects off.
`WordPressTransientStore` uses site transients on multisite. To clear the
library's transients in your plugin's uninstall routine (default prefix):

```php
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_llmjson.%' OR option_name LIKE '\\_transient\\_timeout\\_llmjson.%'");
// On multisite, the same against $wpdb->sitemeta with meta_key and _site_transient_.
```

## Limitations

- Chat completions only; no streaming, tools, embeddings or images.
- The validator covers the keywords strict structured outputs allow.
  Others (`format`, `if`, `oneOf`, ...) are ignored and logged once at
  debug level.
- `MemoryStore` remembers nothing across web requests. Use `FileStore`,
  a PSR-16 cache or transients for cooldowns that span requests.
- PSR-16 and transient stores have no atomic increment, so two processes
  failing at the same moment can start a cooldown one failure late.
- The stream and WordPress transports have no separate connect timeout.
- Sentence repair knows English abbreviations only; add your own with
  `withAbbreviations()`. Word counts treat Thai, Lao, Khmer and Burmese
  runs as one word.
- No built-in prices. Pass a `PriceTable`, or rely on the cost OpenRouter
  reports.

## Not yet

- The OpenAI Responses API as a second wire format.
- Per-language abbreviation lists beyond English.

## FAQ

**Why validate when the provider promises strict output?** Routers send
requests to many upstream hosts, and not all of them enforce the schema.

**Why is the 402 not retried?** An empty balance gives the same answer four
seconds later. The provider is skipped for the call and held for a minute.

**My model sometimes takes longer than 90 seconds.** Raise the request
timeout and the deadline: `Options::defaults()->withTimeouts(10, 180)->withDeadline(400)`.

**I suspect the retry logic itself.** `Options::withReliability(false)`
gives plain one-attempt-per-route behaviour with no store traffic. The
token cap, validation and guard stay on.

**How do I test my code without network?** Use
`IndianOpinion\LlmJson\Testing\MockTransport` with `FrozenClock` and
`RecordingSleeper`.

**Which models does it support?** Any model behind a chat completions
endpoint. Strict mode needs a provider and model that support structured
outputs; auto mode falls back when they do not.

Design notes and every decision are in
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). The requirements are in
[`docs/PRD.md`](docs/PRD.md).

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
