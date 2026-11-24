# releases-notes-reach tasks

## 1. Users hear about updates

- [x] 1.1 Dispatch `AppUpdateEvent` from `InstallerService::installAppVersion()` after the pin is settled, for real version changes only, behind `notify_users_on_update`. Verify: `tests/unit/Service/InstallerServiceTest.php` asserts one dispatch for an upgrade, none for a dry run, none for a reinstall of the same version, none with the switch off; `tests/unit/Listener/AppUpdatedListenerTest.php` asserts no drift after a re-pin install. Landed in #486 (`InstallerService::dispatchUpdateEvent()`); the cases are in `tests/unit/Service/InstallerServiceUpdateEventTest.php`, including the dispatch after a re-pin.
- [x] 1.2 Add the switch to `InstanceSettings` and `InstanceSettingsPanel.vue`. Verify: `InstanceSettingsPanel.spec.ts`. `notifyUsersOnUpdate` on the Settings tab, on by default, sharing the installer's `notify_users_on_update` key; `InstanceSettingsTest` and the settings golden cover the store and the audit line.
- [x] 1.3 On a test instance, update a fixture app through Versioniq and confirm a user of that app gets the platform's app updated notification. Verify: `tests/e2e/whats-new.spec.ts` reads the user's notifications through the notifications OCS API. Status: written, runs in nightly (and the Playwright job on development); not yet verified on a live run.
  - Written: `tests/e2e/whats-new.spec.ts`; waits for the Playwright job.

## 2. Notes in the admin's language

- [x] 2.1 Add `changelogLanguage` to App Store and forge version entries. Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` for a Dutch translation, an English fallback and a forge body.
- [x] 2.2 Add `NotesTranslator` and `POST /api/changelog/translate`, admin-only, with the stored result. Verify: unit tests with a stubbed `ITranslationManager` (translated once, second call served from store) and one without providers (409 with a clear message).
  - Built on Nextcloud task processing (`core:text2text:translate`, `OCP\TaskProcessing\IManager::runTask()`) instead of `ITranslationManager`, which is deprecated since Nextcloud 30, below this app's minimum of 32, and would fail psalm at level 1. Same behaviour: availability from the task types, translated once and then served from app config, 409 without a provider. `lib/Service/Release/NotesTranslator.php`, `lib/Controller/ChangelogController.php` (`/api/changelog/translate` and its `/api/v1/` twin, described in `openapi.json`), initial state `notes-translation-available` from `Settings\Admin`; tests `NotesTranslatorTest`, `ChangelogControllerTest`, `AdminTest`.
- [x] 2.3 Add the button and the machine translation label to `VersionChangelog.vue`, shown only with a provider. Verify: `VersionChangelog.spec.ts`.
- [x] 2.4 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.

## 3. Close

- [x] 3.1 Set the matrix rows `rel-user-whats-new` and `rel-notes-language` to `built` with evidence lines, then archive this change.
  - Done: matrix rows set to `built` with evidence lines (code paths on development) and requirement references pointed at `openspec/specs`; specs synced and the change archived. The e2e specs are written and run in the nightly; not yet verified on a live run.
