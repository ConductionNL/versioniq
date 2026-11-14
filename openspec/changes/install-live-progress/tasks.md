# install-live-progress tasks

## 1. Reporter

- [x] 1.1 Add `InstallProgress` on the distributed cache with a ten-minute lifetime and a swallowing `stage()`. Verify: `tests/unit/Service/Installer/InstallProgressTest.php`, including a cache that throws.
- [x] 1.2 Call `stage()` next to each `addDebug()` in both installers, and in `InstallFinalizer` for migrations and repair steps, with download progress every 5 %. Verify: installer unit tests assert the stage order for a signed install, an external install and a failed checksum.
  - Both installers report from inside their `addDebug()`, so every debug stage is reported under the same name, without the debug detail (temp paths, URLs). `InstallFinalizer` reports `finalize`, the repair step groups and `migrations`. Downloads use Guzzle's `progress` option through `InstallProgress::downloadProgress()`, as `downloading` with `percent` every 5 %. Tests: `InstallerProgressFeedTest` (signed order, an external install that stops at the checksum, no reporter) and two `InstallProgressTest` cases. The golden installer tests fail with and without this change in this environment (they expect an unwritable apps folder).

## 2. Endpoint and session

- [x] 2.1 Accept `progressToken` on the install route, close the session after the middleware, and add `GET /api/install-progress/{token}`. Verify: `tests/unit/Controller/ApiTest.php` (403 for a non-admin, 404 for an unknown token) and a unit test that `installAppVersion()` never writes to the session.
  - `InstallVersionFields::$progressToken`; `installVersion()` closes the session (after the admin check and the middleware) and starts the record when a token came, and finishes it with the install's status, or `failed` for a 4xx/5xx. `GET /api/install-progress/{token}` and its `/api/v1/` twin (admin-only, 404 for an unknown or expired token) are in `openapi.json`. Tests: `ApiInstallProgressTest` (404, 200, the order close → start → install → finish with no session write, a failed install, and that no installer class uses `ISession`) and the route in `ApiNonAdminRefusalTest`.

## 3. Page and CLI

- [ ] 3.1 Add `InstallProgress.vue` with the checklist, elapsed time and the stay-on-page line, polled once a second. Verify: `InstallProgress.spec.ts` with a running, a finished and a failed record.
- [ ] 3.2 Print stages from `occ versioniq:install`, none with `--json`. Verify: `tests/unit/Command/InstallVersionTest.php`.
- [ ] 3.3 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 3.4 Extend `tests/e2e/install-effects.spec.ts`: during a fixture install the page shows at least the download and extraction stages before the result.

## 4. Close

- [ ] 4.1 Set the matrix row `ins-progress` to `built` with evidence lines, then archive this change.
