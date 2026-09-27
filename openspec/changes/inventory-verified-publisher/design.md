# Design: inventory-verified-publisher

Read against `development` at 02e1050 (2026-09-27).

## Context

- `DiscoveryHit` (`lib/Service/Discovery/DiscoveryHit.php:27-37`) carries app id, name, summary, icon, provider, binding, `installable`, its reason and a homepage. No publisher.
- `AppStoreDiscovery` caches a projection of the catalogue with `CACHED_FIELDS = ['id', 'name', 'summary', 'description', 'categories', 'preview', 'website']` (`lib/Service/Discovery/AppStoreDiscovery.php:51`), kept small on purpose because Nextcloud loads every config value of an app at once (line 53-60). The catalogue entry also has `authors` and `isFeatured`, which the projection drops.
- `GithubSearchDiscovery::buildHit()` (`lib/Service/Discovery/GithubSearchDiscovery.php:130-170`) has the repository owner and marks a hit installable only when the trusted-source allowlist allows it (line 145).
- `InstallerService::getInstalledApps()` (`lib/Service/InstallerService.php:89-170`) already knows `isShipped` and the bound source of every card.
- `AppStoreSource` caches each app's full catalogue entry, `authors` and `isFeatured` included (`lib/Service/Source/AppStoreSource.php:490-520`).

## Goals and non-goals

Goals: show who publishes an app and which trust marks the data backs, and point out look-alike names.

Non-goals: a curated recommendation list, publisher-change warnings.

## Decisions

### D1. Four marks, each from a named source of truth

`PublisherTrust::marksFor(appId, binding, catalogueEntry)` returns any of:

| Mark | Source |
|---|---|
| `shipped` | `IAppManager::isShipped()` |
| `featured` | App Store `isFeatured` |
| `verifiedOrg` | GitHub `GET /orgs/{owner}` returns `is_verified: true` |
| `trusted` | `TrustedSourceList::isAllowed()` |

The publisher name is the App Store `authors[].name` joined, or the forge owner. The GitHub answer is cached per owner for a week in app config (`publisher.github.<owner>`), and a user account (404 on the orgs endpoint) is cached as "not an organisation".

Alternative considered: a list of "trusted" publishers shipped with Versioniq. Rejected: it would be a claim Versioniq cannot back, and the matrix row asks for the platform's and the publisher's own signals.

### D2. The catalogue projection grows by two fields

`CACHED_FIELDS` gains `authors` (names only, emails dropped) and `isFeatured`. Both are short. The size guard on the projection (`discardOversizedCache`, line 193) stays in place.

### D3. Similar names

`DiscoveryAggregator` normalises each hit's name (lower case, spaces, dashes and underscores removed, a trailing `app` removed) and gives every hit that shares a normalised name with a hit from another publisher a `similarTo` list. `DiscoverPanel.vue` renders it as "Similar name: {name} by {publisher}".

### D4. Where it shows

`src/components/PublisherMark.vue` renders the publisher and the marks; `DiscoverPanel.vue` uses it on each hit and `App.vue` on each card under the id. A mark carries a tooltip that says where it comes from ("The App Store marks this app as featured.").

## Risks and trade-offs

- [A featured or verified mark reads as a guarantee] → each tooltip says who made the claim, and nothing is called "safe".
- [The GitHub organisation call costs one request per new owner] → cached for a week, made only for hits the admin is looking at.
- [A false similar-name hint] → it is a hint that names both publishers, and it never blocks an install.

## Migration

No schema change. The discovery cache is rebuilt on its normal expiry with the two extra fields. Rollback: revert.
