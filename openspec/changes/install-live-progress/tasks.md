# install-live-progress tasks

## 1. Reporter

- [x] 1.1 Add `InstallProgress` on the distributed cache with a ten-minute lifetime and a swallowing `stage()`. Verify: `tests/unit/Service/Installer/InstallProgressTest.php`, including a cache that throws.
- [x] 1.2 Call `stage()` next to each `addDebug()` in both installers, and in `InstallFinalizer` for migrations and repair steps, with download progress every 5 %. Verify: installer unit tests assert the stage order for a signed install, an external install and a failed checksum.
  - Both installers report from inside their `addDebug()`, so every debug stage is reported under the same name, without the debug detail (temp paths, URLs). `InstallFinalizer` reports `finalize`, the repair step groups and `migrations`. Downloads use Guzzle's `progress` option through `InstallProgress::downloadProgress()`, as `downloading` with `percent` every 5 %. Tests: `InstallerProgressFeedTest` (signed order, an external install that stops at the checksum, no reporter) and two `InstallProgressTest` cases. The golden installer tests fail with and without this change in this environment (they expect an unwritable apps folder).

## 2. Endpoint and session

- [x] 2.1 Accept `progressToken` on the install route, close the session after the middleware, and add `GET /api/install-progress/{token}`. Verify: `tests/unit/Controller/ApiTest.php` (403 for a non-admin, 404 for an unknown token) and a unit test that `installAppVersion()` never writes to the session.
  - `InstallVersionFields::$progressToken`; `installVersion()` closes the session (after the admin check and the middleware) and starts the record when a token came, and finishes it with the install's status, or `failed` for a 4xx/5xx. `GET /api/install-progress/{token}` and its `/api/v1/` twin (admin-only, 404 for an unknown or expired token) are in `openapi.json`. Tests: `ApiInstallProgressTest` (404, 200, the order close → start → install → finish with no session write, a failed install, and that no installer class uses `ISession`) and the route in `ApiNonAdminRefusalTest`.

## 3. Page and CLI

- [x] 3.1 Add `InstallProgress.vue` with the checklist, elapsed time and the stay-on-page line, polled once a second. Verify: `InstallProgress.spec.ts` with a running, a finished and a failed record.
  - `src/components/InstallProgress.vue` polls `GET /api/install-progress/{token}` once a second and shows each stage ticked, the running one with its elapsed seconds (and the download percentage), the failed one marked, and the maintenance-mode line. `App.vue` makes a random token per install, sends it as `progressToken` (not on the downgrade preview's forced dry run) and shows the checklist above the result panel while the install runs. `InstallProgress.spec.ts` (4): running, finished, failed, polling stops on unmount. The App request snapshots and the performInstall golden gain only the token; those specs pin it and stub the checklist.
- [x] 3.2 Print stages from `occ versioniq:install`, none with `--json`. Verify: `tests/unit/Command/InstallVersionTest.php`.
  - `InstallProgress::listen()` is told each new stage, with or without a token; `InstallVersion` prints each as it starts and removes its listener afterwards, and `--json` registers none. A failing listener is swallowed. Tests: two `InstallVersionTest` cases and `InstallProgressTest::testAFailingListenerNeverFailsTheInstall`.
- [x] 3.3 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
  - en and nl strings for every new label, built into `l10n/*.js`; `npm run check:l10n-js` passes.
- [x] 3.4 Extend `tests/e2e/install-effects.spec.ts`: during a fixture install the page shows at least the download and extraction stages before the result. Status: written, runs in nightly (and the Playwright job on development); not yet verified on a live run.
  - Written: the "live install progress" block in `tests/e2e/install-effects.spec.ts` (the dry run in the settings page asserts the checklist with Download and Extracting, in that order, before the result); waits for the Playwright job.

## 4. Close

- [ ] 4.1 Set the matrix row `ins-progress` to `built` with evidence lines, then archive this change.
