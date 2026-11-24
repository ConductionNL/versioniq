---
kind: code
---

# Proposal: releases-notes-reach

## Why

Release notes reach one person today: the admin who opens the version list. The people who use an app never hear what changed after Versioniq updated it, although they do when the same app is updated from Nextcloud's own Apps page. And an admin reads the notes in whatever language the publisher wrote, so a Dutch municipality reads English notes for most apps.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers two rows about who reads the release notes, and in which language.

| Row | Rating now | What is missing |
|---|---|---|
| `rel-user-whats-new` | no | Versioniq shows changelogs to admins only. Its installer never dispatches the platform's `AppUpdateEvent` (checked for this change: `lib/Service/Installer/InstallFinalizer.php:73-170` runs migrations and repair steps and dispatches nothing), so the users' "what's new" notice that Nextcloud sends after its own updates never fires. |
| `rel-notes-language` | partial, built | The missing half: App Store notes appear in the admin's language only when the publisher translated them; forge release bodies appear as written. Nothing translates the rest. |

### Demand

- `rel-user-whats-new`: feature request, https://github.com/nextcloud/server/issues/49935.
- `rel-notes-language`: tender, https://www.tenderned.nl/aankondigingen/overzicht/408309. Bestuurs- en Raadsinformatiesysteem, requirement 133821: Dutch-language release notes delivered with every release.

### Competitors rated yes (evidence quoted from the matrix)

- `rel-user-whats-new`, Nextcloud rated yes: "After an app update, users for whom the app is enabled get an app_updated notification linking to the CHANGELOG entry (apps/updatenotification/lib/Listener/AppUpdateEventListener.php, apps/updatenotification/lib/BackgroundJob/AppUpdatedNotifications.php:44-100, apps/updatenotification/lib/Controller/ChangelogController.php:40)".
- `rel-notes-language`: no competitor rated yes. Nextcloud is partial ("shows the release changelog in the admin's language when the publisher supplied a translation, falling back to English ... nothing translates notes the publisher wrote in one language"), and so is Easy Updates Manager through WordPress core ("untranslated notes ... stay in English, and there is no machine translation").

## What changes

- After a real update through Versioniq, Versioniq dispatches the platform's `AppUpdateEvent`, the same event Nextcloud's own update path sends. Nextcloud then tells the app's users what changed, exactly as it does for an update from its Apps page.
- An admin can switch that off for Versioniq's updates on the Settings tab.
- When release notes are not in the admin's language and the instance has a translation provider, the notes get a "Translate to {language}" button. The translation is labelled as a machine translation and kept, so the same notes are translated once.

## Scope

In scope: dispatching the event at the right moment, the setting, the translate button, the translation store, tests.

Out of scope:
- Writing a what's-new text for apps that ship no changelog. The platform's notice links to the app's own changelog.
- Translating without a provider. Versioniq ships no translation engine; it uses what the instance has (for example a local translation app or a DeepL integration).
- Translating advisories. No row asks for it.

## Impact

- Changed: `lib/Service/InstallerService.php` (dispatch after the pin is settled), `lib/Service/Source/AppStoreSource.php` (report the language the notes are in), `lib/Service/Source/ForgeReleaseSource.php` (language unknown), `lib/Service/Settings/InstanceSettings.php` (one switch), `lib/Controller/ApiController.php` (translate route), `src/components/VersionChangelog.vue`, `src/components/InstanceSettingsPanel.vue`, `l10n`.
- New: `lib/Service/Changelog/NotesTranslator.php`.
- ADDED requirements in `changelog-visibility`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The switch (`notify_users_on_update`) and the stored translations (`notes_translation.*`) are app config keys that are inert without the code.
