# Changelog

All notable changes to this project are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Not yet

- The OpenAI Responses API as a second wire format.
- Abbreviation lists for sentence repair in languages other than English.

## [0.1.0] - 2026-11-11

### Added

- `Client` with an ordered chain of routes (provider plus model), per-call
  chain override, total deadline and timeout clipping.
- Providers for OpenRouter, OpenAI and any chat completions endpoint, with
  https enforcement, attribution headers and configurable credit patterns.
- Output modes strict, JSON and prompt, chosen automatically per route; a
  strict-to-JSON downgrade learned from a format rejection and remembered
  in the store.
- Response classification into one outcome per attempt, including
  truncation, refusals, content filters, errors inside a 200, credit
  exhaustion in 402, 429, 403 and 200 bodies, and redirects.
- Same-route retries with jittered backoff and `Retry-After` support.
- Cooldowns, credit holds and a daily chain failure counter in a pluggable
  store: `MemoryStore`, `FileStore`, `Psr16Store`,
  `WordPressTransientStore`. The store fails open.
- Local validation of strict structured output keywords on every output,
  telling JSON objects from arrays exactly, and again after any guard
  repair.
- Guard rules G1 to G12: truncation, required fields, invisible and control
  characters, replacement characters, unfinished prose with repair,
  continuation items, word floors with one regeneration, quote
  instruction, script share, mixed-script words and custom rules.
- Token and cost accounting per attempt and per call, from reported cost
  or a caller price table; a total with an unpriced billed attempt is
  marked `CostSource::Partial`.
- Events for attempts, retries, skips, cooldowns, credit exhaustion, guard
  repairs and rejections, and chain failure.
- `Log\Logger` interface with a PSR-3 adapter; nothing is logged without a
  logger.
- Transports: curl (default), PHP streams (fallback), WordPress.
- Test doubles for users: `MockTransport`, `FrozenClock`,
  `RecordingSleeper`, `RecordingLogger`.
- `bootstrap.php` for use without Composer.

[Unreleased]: https://github.com/IndianOpinion/llm-json-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/llm-json-php/releases/tag/v0.1.0
