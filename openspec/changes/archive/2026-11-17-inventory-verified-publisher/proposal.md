---
kind: code
---

# Proposal: inventory-verified-publisher

## Why

The App Store has several apps with look-alike names and unclear maintainers. An admin searching Discover for a whiteboard gets a list and no way to tell the one Nextcloud ships from a fork by a stranger. The only trust mark Versioniq shows today is the admin's own trusted-source allowlist, which says what the admin allowed, not who publishes the app.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `inv-verified-publisher` | no | No publisher or trust level is shown, on Discover hits or on app cards. |

### Demand

- Feature request, https://github.com/nextcloud/appstore/issues/1502. "Clarify which apps are recommended by the Nextcloud team, or trusted developers", opened 2024-10-01 and still open on 2026-09-26: several look-alike whiteboard apps with unclear maintainers.
- The row is in the product's core area (inventory).

### Competitors rated yes (evidence quoted from the matrix)

No competitor is rated yes. Nextcloud is partial: "apps/appstore/src/components/BadgeAppLevel.vue:19-27 shows a Supported badge (covered by the subscription) or a Featured badge (community apps ready for production) ... No mark for apps maintained by Nextcloud or a verified publisher (appstore issue 1502 is open)". The other four are rated no.

## What changes

- Every Discover hit and every app card names its publisher: the App Store authors, or the forge owner.
- A trust mark says what Versioniq can back with data: "Ships with Nextcloud" (the app is shipped with the server), "Featured in the App Store" (the store's own `isFeatured` flag), "Verified organisation" (the GitHub organisation has a verified domain), and "On your trusted list" (the admin's allowlist). An app with none of these shows its publisher and no mark.
- When two Discover hits have names that differ only in case, spaces, dashes or a common suffix, both get a "Similar name" hint that names the other publisher.

## Scope

In scope: publisher and trust fields on hits and cards, the GitHub organisation check, the similar-name hint, tests.

Out of scope:
- A Conduction or Nextcloud "recommended" list that Versioniq curates itself. The marks come from the store, the forge and the admin, never from a list only Versioniq maintains.
- Warning when a new version comes from a different publisher (row `saf-publisher-change`, deferred: no competitor yes, no demand).

## Impact

- Changed: `lib/Service/Discovery/DiscoveryHit.php` (publisher and trust fields), `lib/Service/Discovery/AppStoreDiscovery.php` (`CACHED_FIELDS` gains `authors` and `isFeatured`), `lib/Service/Discovery/GithubSearchDiscovery.php` and `GithubPrivateDiscovery.php` (owner and organisation check), `lib/Service/Discovery/DiscoveryAggregator.php` (similar names), `lib/Service/InstallerService.php` (`getInstalledApps` adds publisher and trust), `src/components/DiscoverPanel.vue`, `src/App.vue` (card), `l10n`.
- New: `lib/Service/Discovery/PublisherTrust.php`, `src/components/PublisherMark.vue`.
- ADDED requirement in `app-discovery`.

### MCP coverage

No MCP surface in this change: Versioniq publishes no `IMcpToolProvider` yet (`admin-mcp-assistant` specifies one).

## Rollback

Revert the change. The organisation check result is cached in app config keys `publisher.github.<owner>`; they are inert without the code.
