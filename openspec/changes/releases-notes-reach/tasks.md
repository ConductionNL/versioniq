# releases-notes-reach tasks

## 1. Users hear about updates

- [ ] 1.1 Dispatch `AppUpdateEvent` from `InstallerService::installAppVersion()` after the pin is settled, for real version changes only, behind `notify_users_on_update`. Verify: `tests/unit/Service/InstallerServiceTest.php` asserts one dispatch for an upgrade, none for a dry run, none for a reinstall of the same version, none with the switch off; `tests/unit/Listener/AppUpdatedListenerTest.php` asserts no drift after a re-pin install.
- [ ] 1.2 Add the switch to `InstanceSettings` and `InstanceSettingsPanel.vue`. Verify: `InstanceSettingsPanel.spec.ts`.
- [ ] 1.3 On a test instance, update a fixture app through Versioniq and confirm a user of that app gets the platform's app updated notification. Verify: `tests/e2e/whats-new.spec.ts` reads the user's notifications through the notifications OCS API.

## 2. Notes in the admin's language

- [ ] 2.1 Add `changelogLanguage` to App Store and forge version entries. Verify: `tests/unit/Service/Source/AppStoreSourceTest.php` for a Dutch translation, an English fallback and a forge body.
- [ ] 2.2 Add `NotesTranslator` and `POST /api/changelog/translate`, admin-only, with the stored result. Verify: unit tests with a stubbed `ITranslationManager` (translated once, second call served from store) and one without providers (409 with a clear message).
- [ ] 2.3 Add the button and the machine translation label to `VersionChangelog.vue`, shown only with a provider. Verify: `VersionChangelog.spec.ts`.
- [ ] 2.4 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.

## 3. Close

- [ ] 3.1 Set the matrix rows `rel-user-whats-new` and `rel-notes-language` to `built` with evidence lines, then archive this change.
