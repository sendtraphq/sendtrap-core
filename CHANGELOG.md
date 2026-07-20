# Changelog

All notable changes to `sendtrap/core` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(`0.x` semantics: breaking changes may land in minors until 1.0).

## v0.5.0 — 2026-07-18

### Added
- **`POST /api/v1/messages/{message}/extract`** — deterministic, named
  extraction from a known message, so verification-code, password-reset and
  magic-link tests need no custom email parsing. Five extractor types:
  `regex` (bounded capture from text, HTML source, subject or a named
  header), `code` (verification-code helper — standalone token of a
  configured length/charset in the visible text; `near` anchors the search
  and the nearest token wins), `link` (select by exact URL, host, path
  prefix, query parameter, visible anchor text or a URL regex; links are
  returned, never fetched), `address` (headers or SMTP envelope — the
  envelope catches BCC-only recipients) and `attachment` (metadata plus the
  authenticated download URL; bytes are never inlined). Results are typed
  and explicit — `found` / `not_found` / `ambiguous` with candidate
  diagnostics instead of guesses (`select: first|last|all` chooses among
  several matches), the source field searched, and a bounded context
  excerpt. Same caps as `/expect`: server-delimited 256-byte regexes, 1 KiB
  values, 10 extractors per request.
- **`extract` inside `/expect`** — matching and extraction in one atomic
  request: extractors run against the first matched message once the count
  requirement and assertions hold, a non-optional miss keeps the wait loop
  polling and reports the new `extraction_failed` status (`422` in strict
  mode), and per-extractor results ride on the response under `extract`.
  "Wait for the signup mail and give me the six-digit code" is one call.
- **`Sendtrap\Core\Contracts\StorageQuota`** — an explicit
  reserve → commit / release admission lifecycle for storage accounting
  (`reserve`, `beginRemoval`, `commit`, `release`, with a
  `StorageReservation` value object carrying the verdict), so hosts can
  make ingestion-time quota decisions atomically under concurrent workers.
  The package default, `Storage\UsageMeterStorageQuota`, is a compatibility
  shim over the host's `UsageMeter` binding — existing hosts keep their
  previous behavior unchanged; a host may bind its own implementation and
  it wins (`bindIf`).
- **`Sendtrap\Core\Storage\MessageDeleter`** — the single deletion path for
  messages. Every deletion surface (API destroy/bulk-destroy, UI delete,
  compat-API clean, pruning, inbox clear, project teardown) now routes
  through it, so on-disk cleanup always runs and freed bytes are always
  returned to the storage quota.

### Changed
- Link discovery (`links` on message detail, the `links` condition field,
  and the new link extractor) now uses a tolerant DOM parse instead of a
  regex scan: malformed markup is recovered, `<area>` links count, visible
  anchor text is captured, and relative URLs are resolved against a
  declared valid absolute `<base href>` (and only then — no base is ever
  guessed, and nothing is fetched). The response shape is unchanged.
- `/expect` count requirements are validated against the evaluator's
  candidate cap: `count.at_least`/`count.exactly` now accept 1–50
  (previously 1–100). A requirement above the cap could never be satisfied —
  the evaluator inspects at most 50 candidates per snapshot.
- Ingestion admission goes through `StorageQuota::reserve()` instead of the
  `UsageMeter::wouldExceedStorage()` check-then-count; with the default
  shim the observable behavior is identical.

### Deprecated
- `UsageMeter::wouldExceedStorage()` — the check historically *counted* the
  accepted bytes as a side effect, a non-atomic read-modify-write that
  loses updates under concurrent workers. Admission belongs to the
  `StorageQuota` lifecycle; the method is retained (and still answered) for
  UI/reporting callers until the next major release.

### Fixed
- `count.exactly` is only confirmed from a provably complete candidate
  set: previously, `exactly: 50` could falsely pass when 51 or more
  messages matched, because the snapshot was silently truncated at the cap.
- The SQL prefilter behind `contains`/`starts_with`/`ends_with` match
  conditions now attaches an explicit `ESCAPE` to its `LIKE`, so literal
  `%` and `_` characters in match values are matched literally on SQLite
  too (SQLite has no default LIKE escape character).
- `scope.unread_only` and `mark_read` reject non-boolean values with a 422
  instead of casting — the string `"false"` was previously treated as
  `true`, silently marking messages read.
- The share routes' `X-Robots-Tag: noindex, nofollow` header is now also
  present on error responses — a bad or expired token's 404/410 previously
  unwound past the middleware and was rendered without it.
- `composer.json` declares `ext-zlib`, which `sendtrap:send-test` has
  required since v0.3.0 for its inline PNG fixture.
- `SECURITY.md` again names the vulnerability-reporting address — v0.4.1
  accidentally shipped the template placeholder — and `composer.json`'s
  `dev-main` branch alias tracks the current minor (it had been stuck at
  `0.1.x-dev` since the initial release).

## v0.4.0 — 2026-07-17

### Added
- **`POST /api/v1/expect`** — the recommended testing endpoint: one
  deterministic request that waits for mail, evaluates expressive match
  conditions (subject, recipients, envelope, bodies, headers, links,
  attachments, quality checks), applies post-match assertions, and returns
  a machine-readable diagnostic distinguishing `no_candidates` /
  `no_match` / `count_mismatch` / `assertions_failed`. Scope cursors
  (`test_id`, `received_after/before`, `after_message_id`, `unread_only`),
  `at_least`/`exactly` count semantics, `mark_read` consumption, and a
  `strict` mode that turns an unmet expectation into HTTP 422. Requests are
  fully validated before any message parsing; evaluation is bounded (SQL
  narrowing, capped candidates, delimited regexes). `/assert` is unchanged.
- `Sendtrap\Core\Contracts\MessageWaiter` — the transport-neutral wait seam
  behind `/expect`; hosts can rebind it to replace polling with push
  notification infrastructure without a public API change.

### Fixed
- Every public share route now answers with `X-Robots-Tag: noindex,
  nofollow` — tokenized share URLs expose captured mail and must never
  enter a search index, and robots.txt rules alone don't stop URL-only
  indexing of links posted somewhere crawlable.

## v0.3.0 — 2026-07-17

### Added
- **`sendtrap:send-test`** — seed an inbox with one rich example message
  (multipart HTML + plain text, a file attachment, an inline `cid:` image,
  unresolved merge tags, an envelope-only BCC recipient, a failing lint
  check, and caniemail-flagged CSS) so a fresh install has something to
  explore before any application is wired up. Default path injects straight
  into the ingestion pipeline (no daemon needed); `--via-smtp` delivers over
  a real loopback SMTP conversation — EHLO, STARTTLS when advertised, AUTH
  LOGIN with the inbox's own credentials. `--inbox=` selects by id or name;
  the only inbox is used automatically, several prompt.

## v0.2.0 — 2026-07-17

### Added
- **OpenAPI 3.1 contract** for the entire token API at `openapi/sendtrap.yaml`
  — every `/api/v1` route and the Mailtrap-compatible aliases, the common
  error schema, rate-limit headers, wait/assert semantics, and plan-gated
  availability via the `x-sendtrap-availability` extension. Ships with a
  generated JSON twin, a Postman collection, and `openapi/generate.sh` to
  rebuild both. Validated against the live routes and responses.
- **Filtered bulk delete**: `DELETE /api/v1/messages` now accepts the same
  filters as the list endpoint (`test_id`, `to`, `search`,
  `subject_contains`), so a test run sharing an inbox can delete only its own
  messages. With no filters the behaviour is unchanged (delete all).

## v0.1.1 — 2026-07-16

### Fixed
- Links inside the message preview iframe now open in a new tab
  (`<base target="_blank">` injected into the rendered preview HTML) instead
  of navigating the sandboxed iframe, which most sites refuse to render.

## v0.1.0

Initial public release.

- Embedded ReactPHP SMTP ingestion server (STARTTLS, per-workspace
  enforcement through host contracts).
- MIME processing pipeline: multipart parsing, attachments with checksums,
  envelope/BCC capture, merge-tag detection.
- Workspace → Project → Inbox → Message/Attachment domain with factories and
  an append-only migration set.
- Bearer-token inbox REST API (list/filter, detail, raw/HTML, attachments,
  wait-for-message) with host-registered rate limiters.
- Message checks: deliverability lint checks and HTML client-compatibility
  scoring against the caniemail dataset (see NOTICE).
- Public share links, webhooks, auto-forwarding.
- Host contracts: WorkspaceContext, WorkspaceAccess, Entitlements,
  UsageMeter, LegacyOwnershipFallback.
- Public testing utility: `Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer`.
