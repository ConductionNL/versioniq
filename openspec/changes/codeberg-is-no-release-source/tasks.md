# Tasks: codeberg-is-no-release-source

## 1. Spec

- [x] 1.1 Remove "Codeberg releases as a source [MVP]" from `external-sources`, change the allowlist API and binding UI requirements, and add "A retired forge keeps what was stored under it". Verify: `openspec validate codeberg-is-no-release-source --strict`.
- [x] 1.2 Drop Codeberg from the Forge abstraction scenario in `sources-gitlab-forge`.

## 2. Code and tests

- [x] 2.1 Point `tests/e2e/external-sources.spec.ts` and `tests/e2e/panels.spec.ts` at a GitHub or self-hosted Forgejo fixture instead of the Codeberg path, and add a unit test that `POST /api/source/{appId}/bind` and `POST /api/trusted-sources` refuse forge `codeberg` (`tests/unit/Controller/ApiTest.php`). Built 14 Nov: `InstallerService::bindSource` and `addTrustedPattern` throw `RetiredForgeException` for `codeberg`, which the controller answers with 400 (`ApiTest::testBindSourceOnTheRetiredCodebergForgeIsRefused`, `testAddTrustedSourceOnTheRetiredCodebergForgeIsRefused`, `InstallerServiceTrustedPatternTest`). The e2e specs already bind through the GitHub and Forgejo fixtures; their only Codeberg mention is a stored allowlist pattern, which stays valid. `tests/e2e/external-sources.spec.ts` no longer exists.
- [x] 2.2 Decide whether `TrustedSourceList::DEFAULT_PATTERNS` (`lib/Service/Source/TrustedSourceList.php:34`) keeps `codeberg:Conduction/*`; drop it if not, and change "Trusted-source allowlist [MVP]" with it.

## 3. Close out

- [ ] 3.1 Archive this change after `sources-gitlab-forge`, so the main spec follows both.
