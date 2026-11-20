# Design: pinning-ranges-and-skips

Read against `development` at 0db8937 (2026-09-27; no code change since 02e1050).

## Context

- A policy is `{level, setBy, setAt}` under app config `policy.{appId}` (`lib/Service/Policy/Policy.php:18-40`, `PolicyStore::set()` at `lib/Service/Policy/PolicyStore.php:96`). `Policy::fromArray()` reads only those keys, so an added key is ignored by older code.
- `CandidateSelector::select()` (`lib/Service/AutoUpdate/CandidateSelector.php:46-64`) keeps versions newer than installed and within the level; `withinLevel()` (line 66-87) is the range rule, and a non-semver version never qualifies for patch or minor.
- `AutoUpdateJob` asks the selector (`lib/BackgroundJob/AutoUpdateJob.php:156`) and skips a version the `AttemptLedger` has seen (`lib/Service/AutoUpdate/AttemptLedger.php:49`). The ledger keeps ten entries per app under `auto_attempt.{appId}` and has a retry path (`forget()`, line 104; `DELETE /api/app/{appId}/attempts/{version}`).
- `InstallerService::installAppVersion()` holds the guards in order: manageable, downgrade (line 471-495), pin (line 498-520, 409 with category `pinned` unless `overridePin`), same version, pre-flight. The picker handles the pin 409 with `PinOverrideDialog.vue`.
- `PolicySelector.vue` renders the level select on each card (`src/App.vue:2280-2288`).

## Goals and non-goals

Goals: the level holds for manual installs when the admin wants it to, and one bad version can be skipped without blocking later ones.

Non-goals: the platform updater, expression ranges, pin expiry.

## Decisions

### D1. The range guard reuses the level

`Policy` gains `holdManual` (bool, default false). When it is true and the level is `patch` or `minor`, `installAppVersion()` refuses, after the pin guard, a real install whose target is newer than installed and outside `CandidateSelector::withinLevel()`, with 409 and category `outOfRange`, naming the level. `overrideRange=1` lets it through and writes an audit row `range_override`. A downgrade is not an update and is left to the downgrade guard. A pinned app answers with the pin guard first.

Alternative considered: a range on the pin. Rejected: a pin means "this exact version" in the spec and the drift logic; a second meaning would blur both.

### D2. The skip list

`SkipList` stores `[{version, skippedBy, skippedAt, reason}]` under `skip.{appId}`, at most 20 entries. `PUT /api/app/{appId}/skip/{version}` and `DELETE` on the same path are admin-only with non-strict password confirmation, like the other writes (`lib/Controller/ApiController.php:468`). `CandidateSelector::select()` gets the skipped versions and drops them before choosing, so the job moves on to the next qualifying version. The availability sweep drops them before picking `newestCompatibleVersion`. The picker marks a skipped version and offers Unskip; installing it by hand shows one confirmation that it was skipped, and why.

Alternative considered: reuse the attempt ledger. Rejected: the ledger is a record of what the job did, capped at ten and cleared by Retry; a skip is a decision an admin makes and must survive both.

### D3. Audit

`skip_version` and `unskip_version` rows carry the version and reason; `range_override` carries the level and the target.

## Risks and trade-offs

- [An admin reads the switch as blocking Nextcloud's own updater] → its help text says it holds Versioniq's installs only; pin drift detection still reports what the platform changed.
- [A skip list hides a security fix] → the advisory badge and recommended version ignore skips, so a skipped version that fixes an advisory is still named there.

## Migration

No schema change. Existing policies read `holdManual` as false. Rollback: revert.
