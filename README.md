# sendtrap/core

The public core of [Sendtrap](https://github.com/sendtraphq): a Laravel package
providing an embedded SMTP ingestion server, MIME processing, and the
Workspace → Project → Inbox → Message domain that Sendtrap distributions are
built on.

## What's inside

- **SMTP ingestion server** (`php artisan mail:smtp-server`) — a ReactPHP
  socket server that accepts SMTP conversations (including STARTTLS), enforces
  per-workspace limits through host-bindable contracts, and hands accepted
  messages to the ingestion pipeline.
- **MIME processing** — full multipart parsing, attachment
  extraction/checksums, envelope/BCC capture, merge-tag detection.
- **Domain** — `Workspace` → `Project` → `Inbox` → `Message`/`Attachment`
  Eloquent models, factories, and an append-only migration set.
- **Token API** — a bearer-token REST API per inbox (list/filter messages,
  detail JSON, raw/HTML views, attachments, filtered bulk delete), with rate
  limiting hooks. `/expect` is the testing endpoint: one deterministic request
  that waits for mail, evaluates match conditions, applies assertions and
  answers with machine-readable diagnostics; named extractors — on `/expect`
  or `POST /messages/{id}/extract` — pull verification codes, links,
  addresses and attachment metadata out of a message server-side, with
  explicit found/ambiguous states instead of guesses. The full surface is documented by an
  OpenAPI 3.1 contract shipped in this package at
  [`openapi/sendtrap.yaml`](openapi/sendtrap.yaml) (JSON twin and a Postman
  collection alongside; regenerate with `openapi/generate.sh`). Hosts serve it
  interactively — e.g. Sendtrap Community at `/docs/api/reference`.
- **Message checks** — deliverability lint checks and an HTML client
  compatibility check scored against the caniemail dataset (see
  [NOTICE](NOTICE) for attribution).
- **Shares & delivery** — public share links, webhooks, auto-forwarding.
- **First-run seeding** (`php artisan sendtrap:send-test`) — one command
  drops a rich example message (HTML + text, attachment, inline `cid:`
  image, envelope-only BCC, merge tags) into an inbox with no configured
  application; `--via-smtp` delivers it over a real loopback SMTP
  conversation (STARTTLS + AUTH) instead of injecting into the pipeline.
- **Host contracts** — `WorkspaceContext`, `WorkspaceAccess`, `Entitlements`,
  `UsageMeter`, `LegacyOwnershipFallback`: every product-policy decision
  (access, limits, quotas) is delegated to the consuming host application
  through these seams. The package ships no billing or account concepts.

## Installation

```bash
composer require sendtrap/core
```

PHP 8.3+ is required. The service provider
(`Sendtrap\Core\SendtrapCoreServiceProvider`) is auto-discovered; package
migrations load automatically and are append-only after the first release.

## Host requirements

The package boots with no host wiring at all, but a host must supply the
following for the code paths it uses (the authoritative, commented list lives
on `SendtrapCoreServiceProvider`):

1. **The `inbox-api` / `inbox-api-wait` rate limiter names** — registered in
   the host's own service provider `boot()` with `RateLimiter::for()`; the
   package's routes reference exactly these two names.
2. **A route named `dashboard`** — the bundled message-reader UI component
   links back to it.
3. **Filesystem disks** — `config('filesystems.default')` must be a defined
   disk; the storage migration command additionally expects disks literally
   named `local` and `s3` to both exist during a migration run.
4. **`config('services.spamcheck.*')`** — optional; spam scoring is a
   disabled-by-default no-op until a host publishes
   `services.spamcheck.enabled/url/timeout/threshold`.

## Public testing utilities

`Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer` is a supported,
host-facing test helper (deliberately under the production `src/` autoload so
consuming applications can use it): it boots the real SMTP server on an
ephemeral port inside the test process and scripts SMTP conversations against
it. It imports only production dependencies.

## HTML compatibility data

The HTML compatibility check scores markup against the
[caniemail](https://www.caniemail.com) support dataset
(CC-BY-4.0 — see [NOTICE](NOTICE)). Hosts vendor the dataset at
`resources/data/caniemail/features.json` and refresh it with
`php artisan htmlcheck:sync-data`.

## Developing against unreleased core

`main` carries a branch alias for the current release minor (see
`extra.branch-alias` in this repository's `composer.json`), so a consuming
application can require unreleased core while still satisfying its caret
constraint — with the alias at `0.5.x-dev`, for example:

```json
{ "require": { "sendtrap/core": "dev-main as 0.5.x-dev" } }
```

## Versioning and support

Semantic versioning on a `0.x` line: breaking changes may land in minor
releases until `1.0`, always with CHANGELOG/UPGRADE notes. Only the latest
`0.x` minor is supported. See [SUPPORT.md](SUPPORT.md) and
[CHANGELOG.md](CHANGELOG.md).

## Security

Please do not open public issues for vulnerabilities — see
[SECURITY.md](SECURITY.md) for the private disclosure process.

## License

[MIT](LICENSE). The "Sendtrap" name and logo are not covered by the code
license — see [TRADEMARK.md](TRADEMARK.md).
