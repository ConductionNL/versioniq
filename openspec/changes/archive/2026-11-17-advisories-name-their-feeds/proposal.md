---
kind: config
---

# Proposal: advisories-name-their-feeds

## Why

The requirement "The installed/pinned version is correlated against known security advisories" says where advisories come from, and says it wrong. It names "the Nextcloud App Store security information" for App Store apps, and Codeberg for forge apps. The App Store publishes no advisory data (measured 2026-08-21, issue #166), so the code reads Nextcloud's own advisory feed instead, and Codeberg is no longer a release source (decision 392). No scenario names a feed at all.

Two built rows of `openspec/parity/capabilities.json` depend on this requirement and found only part of their claim in it during the 12 Nov verify pass:

| Row | What is missing |
|---|---|
| `adv-nextcloud-feed` | No scenario says the Nextcloud advisory feed is read. |
| `adv-github-advisories` | No scenario says a GitHub-bound app is checked against its repository's security advisories. |

## What changes

- `security-advisory-correlation`, "The installed/pinned version is correlated against known security advisories": the text names the two feeds the code reads, drops Codeberg, and gains two scenarios, one per feed.
- `capabilities.json`: `adv-nextcloud-feed` and `adv-github-advisories` cite this change.

No code changes. The behaviour is built:

- `lib/Service/Advisory/NextcloudAdvisoryFeed.php` reads `nextcloud/security-advisories` once per sweep, overridable through `advisory.feed_base`.
- `ForgeReleaseSource::listAdvisories()` (`lib/Service/Source/ForgeReleaseSource.php`) reads `/repos/{owner}/{repo}/security-advisories` on the bound forge with the same token path as release listing.
- `AdvisoryService::correlate()` merges both for an app.

## Impact

- MODIFIED requirement in `security-advisory-correlation`.
- `openspec/parity/capabilities.json`: two rows change their `built.requirement`.

### MCP coverage

No MCP surface in this change.

## Rollback

Revert the change. Nothing runs from it.
