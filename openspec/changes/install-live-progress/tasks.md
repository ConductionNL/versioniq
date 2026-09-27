# install-live-progress tasks

## 1. Reporter

- [ ] 1.1 Add `InstallProgress` on the distributed cache with a ten-minute lifetime and a swallowing `stage()`. Verify: `tests/unit/Service/Installer/InstallProgressTest.php`, including a cache that throws.
- [ ] 1.2 Call `stage()` next to each `addDebug()` in both installers, and in `InstallFinalizer` for migrations and repair steps, with download progress every 5 %. Verify: installer unit tests assert the stage order for a signed install, an external install and a failed checksum.

## 2. Endpoint and session

- [ ] 2.1 Accept `progressToken` on the install route, close the session after the middleware, and add `GET /api/install-progress/{token}`. Verify: `tests/unit/Controller/ApiTest.php` (403 for a non-admin, 404 for an unknown token) and a unit test that `installAppVersion()` never writes to the session.

## 3. Page and CLI

- [ ] 3.1 Add `InstallProgress.vue` with the checklist, elapsed time and the stay-on-page line, polled once a second. Verify: `InstallProgress.spec.ts` with a running, a finished and a failed record.
- [ ] 3.2 Print stages from `occ versioniq:install`, none with `--json`. Verify: `tests/unit/Command/InstallVersionTest.php`.
- [ ] 3.3 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/install-effects.spec.ts`: during a fixture install the page shows at least the download and extraction stages before the result.

## 4. Close

- [ ] 4.1 Set the matrix row `ins-progress` to `built` with evidence lines, then archive this change.
