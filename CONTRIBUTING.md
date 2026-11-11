# Contributing

Thank you for helping. Bug reports, fixes and small focused features are
welcome.

## Setup

```sh
git clone https://github.com/IndianOpinion/llm-json-php.git
cd llm-json-php
composer install
```

PHP 8.1 or later. CI runs 8.1, 8.2 and 8.3, so do not use features newer
than 8.1.

## Tests and checks

```sh
composer test      # PHPUnit; offline, uses MockTransport
composer analyse   # PHPStan level 8
composer lint      # phpcs, PSR-12
composer check     # all three
```

Live tests are opt-in and cost a fraction of a cent:

```sh
LLMJSON_LIVE=1 OPENROUTER_API_KEY=... OPENAI_API_KEY=... vendor/bin/phpunit --testsuite live
```

## Style

- PSR-12, `declare(strict_types=1);` in every file.
- No runtime dependencies. Optional integrations are checked at runtime.
- No `@` error suppression, no `eval`, no shell calls.
- Typed exceptions; never fail silently.
- Never log or put in an exception the API key, the prompt or the output.
- Documentation: short sentences, plain words, British spelling, no em
  dashes.

## Pull requests

- One change per pull request, with tests. A behaviour change names the
  PRD case it touches, or updates `docs/PRD.md` and `docs/ARCHITECTURE.md`.
- Add a line under Unreleased in `CHANGELOG.md`.
- CI must be green.
- A DCO-style sign-off is not required.

By contributing you agree that your work is released under the MIT licence.
