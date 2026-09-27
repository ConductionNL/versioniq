# Design: releases-notes-reach

Read against `development` at 02e1050 (2026-09-27).

## Context

- Nextcloud's own update path dispatches `OCP\App\Events\AppUpdateEvent` from `OC\App\AppManager` (server `lib/private/App/AppManager.php:1189`). The updatenotification app listens for it and queues `AppUpdatedNotifications`, which notifies every user the app is enabled for, linking to the changelog.
- Versioniq's installers swap files and call `InstallFinalizer::finalize()` (`lib/Service/Installer/InstallFinalizer.php:73-170`), which runs migrations, repair steps, job registration and the version writes, and dispatches no event.
- Versioniq itself listens for `AppUpdateEvent` in `lib/Listener/AppUpdatedListener.php:47-58` to detect pin drift. `InstallerService::installAppVersion()` settles the pin after a successful real install (`lib/Service/InstallerService.php:681-705`) precisely so a later drift check does not read Versioniq's own install as drift.
- `AppStoreSource::rawChangelogFrom()` (`lib/Service/Source/AppStoreSource.php:716-746`) picks the admin's language and falls back to English without saying which one it returned. Forge release bodies come from `ForgeReleaseSource::extractChangelog()` (`lib/Service/Source/ForgeReleaseSource.php:495`).
- `VersionChangelog.vue` renders notes as plain text, never `v-html` (`src/components/VersionChangelog.vue:1-10`).
- Nextcloud offers machine translation through `OCP\Translation\ITranslationManager` (`hasProviders()`, `translate()`, since 26), backed by whatever provider app the instance has.

## Goals and non-goals

Goals: users hear about updates made through Versioniq the way they hear about any other update, and an admin can read notes in their own language.

Non-goals: writing changelogs, shipping a translation engine.

## Decisions

### D1. Dispatch after the pin is settled

`InstallerService::installAppVersion()` dispatches `new AppUpdateEvent($appId)` through `IEventDispatcher::dispatchTyped()` after the block that settles the pin (after line 705), only for a real install whose version differs from the one before, and only when `notify_users_on_update` is on (default on, matching the platform). Dispatching there, and not inside `finalize()`, means `AppUpdatedListener` compares the new version with a pin that already reflects the admin's choice, so a re-pin or an unpin never reads as drift.

Alternative considered: call the updatenotification app's job directly. Rejected: it is another app's internals, and the event is the public contract.

### D2. The notes say which language they are in

Version entries gain `changelogLanguage`: the language code the App Store translation was taken from, or null for forge bodies. The page offers translation when `changelogLanguage` differs from the admin's language or is null.

### D3. Translate on request, keep the result

`POST /api/changelog/translate` (admin-only) takes a source id, a version and a target language, reads the notes through the normal listing, and calls `ITranslationManager::translate()`. The result is kept in app config under `notes_translation.<sha1(sourceId|version|lang)>`, capped at 16 KB like the changelog itself (`CHANGELOG_MAX_BYTES` is 8 KB of source text). `VersionChangelog.vue` shows a "Translate to {language}" button when the instance has a provider (`hasProviders()`, sent as initial state) and marks a translated text "Machine translation". The original stays one click away.

Alternative considered: translate every listing up front. Rejected: providers are slow or paid per character, and most notes are never opened.

## Risks and trade-offs

- [Users get a notice for an update the admin did as a test] → the setting switches it off, and a dry run never dispatches.
- [A machine translation misreads a security note] → it is labelled as a machine translation and the original is shown next to it on request.
- [The event now reaches other listeners too] → that is the platform's contract for every update; Versioniq's updates had been silently skipping it.

## Migration

No schema change. The default of the switch matches what users get from the platform today. Rollback: revert.
