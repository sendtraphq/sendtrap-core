# Security Policy

## Reporting a vulnerability

**Please do not open public issues or pull requests for security
vulnerabilities.**

Report vulnerabilities privately by email to **$SECURITY_CONTACT**. Include a
description of the issue, a proof of concept if you have one, and the
affected version(s).

You will receive an acknowledgement within **3 business days**. We aim to
ship a fix (or a documented mitigation) within **90 days** of a confirmed
report, coordinating the disclosure date with you.

## Supported versions

During the `0.x` series, only the **latest published `0.x` minor** receives
security fixes, released as a new patch version. There is no long-term
support branch before `1.0`.

| Version | Supported |
| --- | --- |
| latest 0.x minor | yes |
| older 0.x releases | no — upgrade to the latest minor |

## Coordinated disclosure

Security fixes in `sendtrap/core` ship publicly with synchronized upgrades of
the downstream Sendtrap distributions that consume this package. Advisories
credit reporters unless they prefer otherwise.
