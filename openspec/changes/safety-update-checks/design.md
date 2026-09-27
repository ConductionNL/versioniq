# Design: safety-update-checks

Read against `development` at 02e1050 (2026-09-27).

## Context

- The signed installer validates the unpacked `appinfo/info.xml` (app id, `isAppCompatible()`, `OC_App::checkAppDependencies()`) only inside `if (!$dryRun)` (`lib/Service/SelectedReleaseInstallerService.php:316-350`). In a dry run it unpacks the archive to a temporary folder (`lib/Service/SelectedReleaseInstallerService.php:501-503`, root found at `:559-566`), diffs migrations for a downgrade (`:648-651`) and returns before touching the live folder (`:654-656`). So a dry run of an App Store package never checks compatibility or dependencies.
- The external installer validates the unpacked archive in both modes (`parseAndValidateInfoXml()`, called at `lib/Service/ExternalReleaseInstallerService.php:229`, defined at `:543-605`) and returns its dry-run result before any file swap (`:259-272`).
- `AutoUpdateJob::attemptInstall()` (`lib/BackgroundJob/AutoUpdateJob.php:169-200`) calls `installAppVersion()` once, for real, then records the outcome in the `AttemptLedger` and notifies success or failure with the payload's `category` and `hint`. A failure blocks that version until an admin clicks Retry (`DELETE /api/app/{appId}/attempts/{version}`, `lib/Controller/ApiController.php:686-705`).
- Failure categories and their hints live in `FailureClassifier` (`lib/Service/Installer/FailureClassifier.php`); `versioniq:install` maps categories to exit codes 0 to 9 (`lib/Command/InstallVersion.php:45-56`).
- Outbound calls block local addresses unless `allow_local_remote_servers` is on (`lib/Service/Source/ForgeReleaseSource.php:72`); secrets are encrypted with `ICrypto` (`lib/Service/Pat/PatManager.php:65`).

## Goals and non-goals

Goals: a dry run that means what it says; checks an admin or an operator attaches, run by every dry run and before every automatic install.

Non-goals: checks after an install with automatic rollback (kept out by the archived auto-update proposal, for the forward-only migrations); a staging requirement (`install-multi-instance-promotion`).

## Decisions

### D1. The signed dry run validates like a real install

`SelectedReleaseInstallerService` moves the `info.xml` validation into a method it calls in both modes: on the unpacked root in a dry run, and on the installed path after the swap in a real install, as today. A dry-run failure is reported with category `incompatible` (or the category the classifier gives) and changes nothing. The two installers then agree: a passing dry run means the installer's own checks pass.

### D2. Two kinds of check

| Kind | Set by | What it does | Passes when |
|---|---|---|---|
| `verdict` | an admin, on the Settings tab | `GET` an https address built from a template with `{appId}`, `{version}`, `{fromVersion}` (each URL-encoded), optionally with a bearer token | the answer is 2xx within 15 s |
| `command` | the server operator, in `config.php` under `versioniq.update_checks` | runs a program with an argument list through `proc_open()` with an array, never a shell, with `{appId}`, `{version}`, `{fromVersion}`, `{path}` (the unpacked package) substituted per argument | exit code 0 within its `timeout` (default 60 s, at most 1800 s) |

Each check has a `name` and an optional `apps` list; without one it applies to every app. Verdict checks are stored by `CheckStore` as JSON under `update_checks`, at most 10, the token encrypted and never returned. `PUT /api/update-checks` on `SettingsController` is admin-only, password-confirmed and audited as `settings`. Command checks are read from the system config and shown read-only on the page, with a line saying where they are set.

Alternative considered: let admins enter commands on the page. Rejected. It would turn a web form into a way to run programs on the server. `config.php` is the boundary Nextcloud already uses for what only the operator decides.

### D3. Where checks run

`CheckRunner::run(appId, fromVersion, toVersion, unpackedPath, context)` runs every matching check in order and returns `[{name, kind, passed, detail}]`, where `detail` is the status code or the last 2 KiB of output. Both installers call it in their dry-run branch, before the temporary folder is removed, and add the results to the dry-run result as `checks`. `InstallerService` passes them through to the payload.

In a web request (`context: web`) a command check whose timeout is above 60 s is not run and reports "Runs from the command line and the nightly job only", so a page never waits minutes. `occ versioniq:install --dry-run` and the nightly job use `context: cli` and run every check.

The page's install result shows each check with a pass or fail mark. `occ versioniq:install` prints them and exits 12 when a check failed.

### D4. The gate in the nightly job

`AutoUpdateJob::attemptInstall()` asks `CheckRunner::matches(appId)`. When a check matches, it first runs `installAppVersion()` as a dry run. A failed dry run or a failed check is recorded in the `AttemptLedger` as a failure and notified through `notifyFailure()` with category `checks_failed` and a hint naming the failed check and its detail. The real install runs only after everything passed. The existing Retry clears a blocked version, so a flaky check costs one click.

When no check matches, the job behaves exactly as today: one real install, no dry run.

Alternative considered: always dry-run before an automatic install. Rejected. Without checks the real install already verifies the same things and restores on failure; the dry run would only double the download.

## Risks and trade-offs

- [A command check runs as the web server user] → only the operator can configure one; arguments never pass through a shell; output is truncated and passes the audit logger's token redaction.
- [A gated automatic update downloads the package twice] → only for apps with a matching check; the artifact cache still stores the package after the real install.
- [A verdict endpoint is down] → the check fails and the version is blocked with that reason; the admin retries once the endpoint is back.
- [A check passes on the unpacked package but the app still fails after the swap] → the installer's backup and restore still apply to the real install.

## Migration

No schema change. With no check configured nothing changes for the nightly job. The only visible change on upgrade is that an App Store dry run can now fail on compatibility or dependencies, where before only the real install failed.
