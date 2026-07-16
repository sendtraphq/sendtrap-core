# Changelog

All notable changes to `sendtrap/core` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(`0.x` semantics: breaking changes may land in minors until 1.0).

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
