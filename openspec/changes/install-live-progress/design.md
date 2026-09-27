# Design: install-live-progress

Read against `development` at 02e1050 (2026-09-27).

## Context

- Both installers already name their stages through a private `addDebug($stage, $data)`: the signed installer at `lib/Service/SelectedReleaseInstallerService.php:291` (requested-install), 491 (release-metadata), 545 (downloaded), 557 (archive-extracted), 584 (info-xml), 607 (signature-verified), 651 (migration-diff), 682 (filesystem-updated), 353-394 (last steps and result); the external installer at `lib/Service/ExternalReleaseInstallerService.php:127` to 333 in the same order. The stages end up in the `debug` payload only when debug is on, after the request returns.
- `FailureClassifier` names the stages a failure can happen in (`STAGE_REQUESTED` to `STAGE_FINALIZE`, `lib/Service/Installer/FailureClassifier.php:72-78`), and the result panel shows that stage after a failure.
- `InstallFinalizer::finalize()` (`lib/Service/Installer/InstallFinalizer.php:73-170`) runs pre-migration repair steps, migrations, post-migration steps and install steps without reporting.
- The page sets `isInstallingVersion` and shows a spinner (`src/App.vue:1712-1830`).
- Issue #160 showed that a long request holding the PHP session lock blocks every other request of the same browser session. A progress poll during an install would hit exactly that.
- `occ versioniq:install` calls the same `installAppVersion()` (`lib/Command/InstallVersion.php:118`).

## Goals and non-goals

Goals: show each stage while it runs, in the browser and on the command line, without changing any step.

Non-goals: batch progress, changes to the steps themselves.

## Decisions

### D1. One reporter, fed where the stages already are

`InstallProgress` has `start(token)`, `stage(name, detail)` and `finish(outcome)`. Both installers call `stage()` next to each existing `addDebug()` call, so the stage names are the ones the debug log and `FailureClassifier` already use. `InstallFinalizer` reports migrations and each repair step group. Download progress is reported every 5 % when the response carries a length.

### D2. Stored in the distributed cache for ten minutes

The reporter writes `{stages: [{name, startedAt, endedAt, detail}], outcome}` to `ICacheFactory::createDistributed('versioniq-progress')` under the token, with a ten-minute lifetime. Without a distributed cache it falls back to the local cache, which still works on a single web server.

Alternative considered: app config. Rejected: every write would hit the database, and Nextcloud loads all of an app's config on each request.

### D3. The token and the session

The page creates a random token and passes it as `progressToken` on `POST /api/app/{appId}/versions/{version}/install`. After the middleware (admin check, password confirmation) has run, the install route calls `ISession::close()`, so the browser's progress polls are not blocked by the session lock. The install writes nothing to the session, so closing it changes nothing else. `GET /api/install-progress/{token}` (admin-only) returns the stored record, or 404 when the token is unknown or expired.

### D4. The page

`InstallProgress.vue` replaces the spinner while `isInstallingVersion` is true: a checklist of stages with a tick for done, a running marker with elapsed seconds for the current one, and a line that says maintenance mode is on and the page should stay open. It polls once a second and stops when the install request returns; the result panel then shows as today.

### D5. The command

`InstallVersion` passes a reporter that writes each stage to the console as it starts, and ends with the existing outcome. `--json` keeps its single JSON document and prints no stages.

## Risks and trade-offs

- [Closing the session early breaks something that writes to it later] → the install path is read-only towards the session; a unit test asserts that `installAppVersion()` never touches it.
- [A reporter failure breaks an install] → every `stage()` call swallows its own errors.
- [Polling load] → one small cache read per second, only while an install runs.

## Migration

None. Rollback: revert.
