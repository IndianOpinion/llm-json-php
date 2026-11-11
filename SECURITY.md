# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Reporting a vulnerability

Please do not open a public issue for a security problem. Email
hello@indianopinion.org with a description, the version, and steps to
reproduce. Do not include real API keys.

We acknowledge reports within 3 working days and aim to send a fix or a
plan within 14 days. We will credit you in the release notes unless you
ask us not to.

## Scope

In scope: API key exposure (in logs, exceptions, stores or debug output),
TLS or redirect handling, file store path or permission issues, and
anything that lets model output or a provider response run code.

Out of scope: prompt injection and unsafe model output. The guard is a
quality check, not a security boundary; escape output for wherever you put
it.
