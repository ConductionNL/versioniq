---
kind: code
---

# Proposal: inventory-app-details-card

## Why

Row `inv-app-metadata` of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq) reads "See an app's description, author, licence and links next to its versions." It is marked built, but only half is: the card shows icon, name, summary or description. Author, licence and links are not shown, and no requirement asks for them.

The board VqAppVersies draws the whole row as an "Over <app>" card beside the version list: publisher, licence, installed version and state, links (website, source, report a problem) and the description. The board is the UI canon (decision 130), so this change specifies that card and plans its build.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-app-metadata` | partial | Author, licence and links are not shown on the app. |

Nextcloud's own app management shows author, website and docs links (`apps/appstore/src/components/AppstoreSidebar/AppDetailsTab.vue:171-205`), so an admin who opens Versioniq instead loses information they had.

## What changes

- `InstallerService::getInstalledApps()` adds `author`, `licence` and `links` (`website`, `repository`, `bugs`) to every app, read from the app's own `appinfo/info.xml` through `OC_App::listAllApps()`.
- The version view gets an "About <app>" card as drawn on VqAppVersies: publisher, licence, installed version and enabled state, the three links, and the description.
- The publisher's verified mark is out of scope here: `inventory-verified-publisher` adds it to the same card.

## Scope

In scope: the three fields in the list payload, the card, strings, tests.

Out of scope: the trust marks and similar-name hint (`inventory-verified-publisher`); fetching metadata for apps that are not installed (Discover hits already carry `homepageUrl`).

## Impact

- Changed: `lib/Service/InstallerService.php` (`getInstalledApps`), `src/App.vue` (version view renders the card, `InstalledApp` type), `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`.
- New: `src/components/AppAboutCard.vue`, `src/components/AppAboutCard.spec.ts`.
- ADDED requirement in `version-management`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The new payload fields are read-only and nothing is stored.
