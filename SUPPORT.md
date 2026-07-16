# Support

## Supported versions

`sendtrap/core` follows semantic versioning on a `0.x` line. Only the
**latest published `0.x` minor** is supported: bug fixes and security fixes
land there and are released as patch versions. Breaking changes may land in
minor releases until `1.0` (always with CHANGELOG/UPGRADE notes). The policy
tightens at `1.0` (latest minor plus the immediately-previous minor for
security backports, window to be finalized at that milestone).

## Where to go

- **Bugs and feature requests** — open a GitHub issue with a minimal
  reproduction (package version, PHP version, and for ingestion issues the
  raw SMTP conversation or message if you can share it).
- **Security vulnerabilities** — never a public issue; follow
  [SECURITY.md](SECURITY.md).
- **Questions about integrating the package into your own host
  application** — GitHub Discussions/issues; the host requirements section
  of the [README](README.md) covers the common integration gaps.

## No warranty

This software is provided "as is" under the [MIT license](LICENSE), without
warranty of any kind. Best-effort support only; there is no SLA on issue
responses.
