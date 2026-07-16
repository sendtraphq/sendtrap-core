# Contributing to sendtrap/core

Thanks for your interest in contributing. Please read this whole document —
especially the sign-off requirement and the pre-1.0 maintainership note.

## Running the suite

```bash
composer install
vendor/bin/phpunit                          # full standalone suite
vendor/bin/phpunit --filter CoreDependencyTest   # architecture boundary test
vendor/bin/pint --test                      # code style (Laravel preset)
```

The test suite is fully standalone (Orchestra Testbench boots a minimal host;
sqlite `:memory:`); no external services are needed.

## Developer Certificate of Origin (DCO)

This project uses the DCO, not a CLA. **Every commit must carry a
`Signed-off-by:` trailer matching its author** — add it with:

```bash
git commit -s
```

By signing off you certify the following (Developer Certificate of Origin
1.1, from https://developercertificate.org):

```
Developer Certificate of Origin
Version 1.1

Copyright (C) 2004, 2006 The Linux Foundation and its contributors.

Everyone is permitted to copy and distribute verbatim copies of this
license document, but changing it is not allowed.


Developer's Certificate of Origin 1.1

By making a contribution to this project, I certify that:

(a) The contribution was created in whole or in part by me and I
    have the right to submit it under the open source license
    indicated in the file; or

(b) The contribution is based upon previous work that, to the best
    of my knowledge, is covered under an appropriate open source
    license and I have the right under that license to submit that
    work with modifications, whether created in whole or in part
    by me, under the same open source license (unless I am
    permitted to submit under a different license), as indicated
    in the file; or

(c) The contribution was provided directly to me by some other
    person who certified (a), (b) or (c) and I have not modified
    it.

(d) I understand and agree that this project and the contribution
    are public and that a record of the contribution (including all
    personal information I submit with it, including my sign-off) is
    maintained indefinitely and may be redistributed consistent with
    this project or the open source license(s) involved.
```

## Branch and PR flow

- Branch from `main`, keep changes focused, include tests for behavior
  changes.
- Open a pull request against `main`. CI must pass: the test matrix
  (PHP 8.3/8.4), a lowest-dependency resolve, code style (pint), the
  architecture boundary test, a secret scan (gitleaks), dependency review,
  a license allowlist check, and the DCO check.

## How contributions land (pre-1.0)

Until `1.0` this project has a **single maintainer**, and the working tree
for unreleased changes is maintained outside GitHub. Accepted pull requests
are therefore **imported rather than merged by button-click**: the
maintainer reviews your PR here, applies the patch to the working tree
preserving your `Signed-off-by:` and crediting you with `Co-authored-by:`,
and your change ships in the next release (at which point the PR is closed
as landed, referencing the release). Response times may be slow; please be
patient. Once the project reaches its post-1.0 cadence, PRs merge
conventionally.

## Versioning expectations

`0.x` follows semver's 0.x semantics: **breaking changes may land in minor
versions** until `1.0`. Contract changes (the host-facing interfaces under
`src/Contracts/`) get CHANGELOG/UPGRADE notes, and where cheap the old and
new shapes coexist for one minor with an `@deprecated` marker on the old
one. From `1.0`, a removed or changed contract must first be deprecated for
one full minor version.
