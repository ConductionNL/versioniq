---
kind: code
---

# Proposal: sources-trust-audit-and-offline-feed

## Why

Three rows of the competitor parity matrix (`openspec/parity/capabilities.json`, versioniq) are marked built, but their own notes say a part is missing. The boards VqHistorie, VqOntdekken and VqInstellingen draw the whole row, and the boards are the UI canon (decision 130). This change plans the missing halves.

| Row | Built half | Missing half, as the board draws it |
|---|---|---|
| `aud-log-source` | Binding changes are audited with "Rebound from" text. | Adding or removing a trusted-source pattern and clearing a binding write no audit entry. VqHistorie draws "Vertrouwde bron toegevoegd: codeberg.org/zuiddrecht". |
| `src-discover-github` | Public GitHub search works, off by default, switched on only with the app config key `discovery.github_search_enabled`. | No switch on the page. VqOntdekken draws "Zoeken in openbare GitHub-repository's staat aan" above the hits. |
| `adv-offline-db` | The advisory feed address can point at an internal mirror (`advisory.feed_base`). | No local copy an instance without internet can import. VqInstellingen draws the advisory feed setting with "Voor een server zonder internet: een lokale kopie van de database." |

The fourth row of this group, `aud-export` (VqHistorie "Exporteren: de hele historie of de gefilterde regels als CSV of JSON"), is already planned by the open change `audit-security-reports` (task 2.2); its matrix row now points there.

## What changes

- `AuditLogger` gains the operations `trust_source`, `untrust_source` and `unbind_source`; the routes that add or remove a trusted pattern and clear a binding record them, and the History tab shows them.
- The Discover tab gets a switch for public GitHub search, stored in the existing key through the instance settings.
- The advisory feed setting gets "Import a local copy": an admin uploads a feed file, Versioniq keeps it in app data and reads it instead of the network when the feed source is set to the local copy. The page says the date of the copy.

## Scope

In scope: the three halves, strings, tests.

Out of scope: history export (`audit-security-reports`); fetching the feed on another machine (the admin downloads the file themselves).

## Impact

- Changed: `lib/Service/Audit/AuditLogger.php`, `lib/Controller/ApiController.php` (`addTrustedSource`, `removeTrustedSource`, `clearBinding`, new `POST /api/advisory/feed-copy`), `lib/Service/Settings/InstanceSettings.php` (keys `discovery.github_search_enabled`, `advisory.feed_source`), `lib/Service/Advisory/NextcloudAdvisoryFeed.php`, `src/components/HistoryPanel.vue`, `src/components/DiscoverPanel.vue`, `src/components/InstanceSettingsPanel.vue`, l10n.
- New: `lib/Service/Advisory/LocalFeedCopy.php`.
- ADDED requirements in `audit-trail`, `app-discovery` and `security-advisory-correlation`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. Audit rows with the new operations stay readable as plain entries; the imported feed copy in app data `advisories/` can be deleted.

## Board note

VqInstellingen says what the local copy is for but draws no import control. The control ("Lokale kopie importeren", with the date of the copy) needs a small board touch-up (a board lane, Fable) before the build starts.
