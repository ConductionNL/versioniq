# Design: advisories-triage

Read against `development` at 02e1050 (2026-09-27).

## Context

- `GET /api/advisories` (`lib/Controller/ApiController.php:138`) returns the stored snapshot as is. Each app row carries `state`, `advisories` (id, severity, summary) and `recommendedVersion` (`lib/Service/Advisory/AdvisoryService.php:308-315`). The server row is keyed `:server` (`AdvisoryService.php:79`).
- `AdvisoryNotifier::notifyNewAdvisories()` (`lib/Service/Advisory/AdvisoryNotifier.php:54`) keys each notified pair as `{appId}:{advisoryId}` (`:66`) and remembers them under `advisory.notified` (`:36`). Keys that drop out are forgotten, so a returning advisory notifies again (`:78-83`).
- `AdvisoryDigestNotifier::sendIfDue()` (`lib/Service/Advisory/AdvisoryDigestNotifier.php:64`) counts apps in state `advisory-available` with advisories (`:75-79`).
- `AdvisoriesPanel.vue` lists the server row and every app row with each advisory's severity, id and summary (`src/components/AdvisoriesPanel.vue:32-99`). The app card badge shows the state and the highest severity (`src/App.vue:2233-2237`, `src/utils/advisories.ts:43`, `:74`).
- Modal dialogs live in `src/dialogs/` (for example `src/dialogs/PinDialog.vue`), per the fleet rule that a dialog is its own component.
- `AuditLogger::record()` (`lib/Service/Audit/AuditLogger.php:59`) accepts any `[a-z_]{1,32}` operation (`:41`).

## Goals and non-goals

Goals: a recorded, reversible decision per advisory and app; an owner per advisory; both visible, audited and respected by badge, notifications, digest and automation.

Non-goals: criteria-based auto-dismissal, non-admin assignees, library advisories (see `advisories-bundled-libraries`).

## Decisions

### D1. Triage is stored next to the snapshot, never inside it

App config key `advisory.triage`, JSON map keyed `{appId}|{advisoryId}` (a `|` because `:server` already contains a colon):

```json
{"openregister|GHSA-xxxx-yyyy-zzzz": {
   "assignee": {"type": "user", "id": "bob"}, "assignedBy": "alice", "assignedAt": "2026-09-27T10:00:00+00:00",
   "dismissal": {"reason": "not_used", "comment": "Public shares are off.", "by": "alice",
                 "at": "2026-09-27T10:05:00+00:00", "until": null, "installedVersion": "2.3.0"}}}
```

The sweep keeps writing the raw snapshot. Triage is applied when the snapshot is read, by one function, `AdvisoryTriage::apply(array $row, array $triage, string $now): array`, used by the controller, the notifier, the digest and (through `autoupdate-security-first`) the job. So no reader can disagree about what is dismissed, and reverting the change leaves a correct snapshot behind.

Alternative considered: drop dismissed advisories from the snapshot during the sweep. Rejected: a revert or a bug would then hide advisories silently, and the Dismissed list needs the advisory text.

### D2. What an active dismissal does

A dismissal is active while `until` is null or in the future, and the app's installed version equals `installedVersion`. For an active dismissal, `apply()` moves the advisory from `advisories` to `dismissedAdvisories` on the row and recomputes `state`: a row whose remaining active advisories are empty drops from `pinned-to-vulnerable` to `advisory-available` (or `none` when nothing remains), and `recommendedVersion` is kept only while an active advisory still needs it.

- The notifier treats an actively dismissed pair as already notified, so it never notifies about it, and a reopen does not fire a late notification.
- The digest counts only active advisories.
- The card badge and its severity use active advisories only.

When the dismissal lapses (end date or version change), `apply()` ignores it and the advisory counts again. The next notifier run sees the pair as current and, if it was never notified, notifies.

Reasons: `not_used`, `inaccurate`, `tolerable_risk`, `mitigated`, `fix_planned`. `fix_planned` requires `until`. The comment is optional, at most 1000 characters.

### D3. Assignment

The assignee is `{type: user, id}` for a user in the `admin` group, or `{type: group, id}` for any group, whose admin members are the owners. The route refuses a non-admin user with 400. A group assignment says on the page "Only the admins in this group are notified". Assigning sends `advisory_assigned` to the owners, rendered by `Notifier::prepare()` with the app, the advisory id and who assigned it, linking to the Advisories tab. Unassigning sends nothing.

Alternative considered: any Nextcloud user as assignee. Rejected: every Versioniq endpoint is admin-only, so a non-admin owner could not open what they own.

### D4. Routes

A new `AdvisoryTriageController`, admin-only twice over like `SettingsController` (no `NoAdminRequired`, plus an `isAdmin()` guard), all `PasswordConfirmationRequired(strict: false)`:

- `POST /api/advisory-triage/dismiss` `{appId, advisoryId, reason, comment?, until?}`
- `POST /api/advisory-triage/reopen` `{appId, advisoryId}`
- `POST /api/advisory-triage/assign` `{appId, advisoryId, assignee}` where `assignee` is `user:{uid}`, `group:{gid}` or empty to unassign

Each refuses with 404 an advisory the stored snapshot does not hold for that app, and writes one audit entry: `advisory_dismiss` (message: advisory id, reason, comment), `advisory_reopen` or `advisory_assign` (message: advisory id and assignee), with `to_version` the installed version. `GET /api/advisories` adds a `triage` map for the page.

### D5. The page

On the Advisories tab each advisory row gets Dismiss, Assign and, once assigned, the owner's name. Dismiss opens `src/dialogs/DismissAdvisoryDialog.vue` (reason radio list, comment, end date). Each app and the server block get a collapsed "Dismissed" list with reason, comment, who, when, until and a Reopen button. A filter at the top shows all advisories or only those assigned to me (me directly, or a group I am an admin member of).

## Risks and trade-offs

- [An admin dismisses a real risk to silence a badge] → the reason is required and audited with the admin's name, the Dismissed list is always visible, and every dismissal ends when the version changes.
- [Triage for an advisory the feed later withdraws piles up] → the refresh job stamps `lastSeenAt` on every entry whose advisory is in the saved snapshot, and `AdvisoryTriageStore::prune()` drops entries not seen for 30 days.
- [The key grows] → one small entry per decided advisory; the feed holds a few hundred advisories in total.

## Migration

No schema change. `advisory.triage` is absent everywhere, which reads as nothing triaged. Rollback: revert; the key is inert.
