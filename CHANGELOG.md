# Changelog

All notable changes to `sendtrap/core` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(`0.x` semantics: breaking changes may land in minors until 1.0).

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
