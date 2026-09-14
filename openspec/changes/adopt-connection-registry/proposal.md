---
kind: code
---

# Proposal: adopt-connection-registry

## Why

Versioniq talks to three outside systems. An admin only finds out one of them fails when a version list comes back empty or an advisory badge goes missing.

- **Nextcloud App Store.** Versioniq reads the App Store catalogue for every app installed from it. The catalogue is about 30 MB, and the store answers with an empty body now and then.
- **GitHub releases.** Apps bound to a GitHub repository list their releases through the GitHub API. Without a token, GitHub rate limits the requests.
- **Nextcloud security advisories.** The advisory check reads the feed Nextcloud publishes on GitHub. When it fails, the badges show fewer advisories than there are.

Hydra change `connection-registry` (hydra#667, amended in hydra#673 and hydra#674) gives every app one page of its connections, backed by integriq.

## What changes

- New `lib/Settings/connections.json` with three connections: `appstore`, `github` and `advisories`. All three are `reportedOnly`.
- No `codeberg` row. Conduction retired Codeberg, so the forge code stays and gets no row.
- Versioniq reports what a real request met: a catalogue fetch, a GitHub release request, an advisory check. A report goes out when the status changes, and at most once an hour otherwise.
- Saving or removing a GitHub token asks integriq to look again, then reports what the token check met.
- An Integrations tab in the Versioniq admin settings page lists integriq's `app_connection` rows for `app=versioniq`. It shows only when integriq is installed.
- Add integration opens `/apps/integriq/connections?app=versioniq&link=1`.
- Local `connectionStatus` and `connectionSettingsLabel` formatters with all six statuses, in English and Dutch.
- The admin page opens the tab a `#section-…` link names, and gains the anchors `section-sources`, `section-advisories` and `section-integrations`.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D3, D4, D6, D8, D9 and D12.
- integriq on `development`: the `app_connection` schema, the declaration sync, both events and the Connections overview.

Without integriq the tab is hidden and nothing is sent.

## Out of scope

- Removing the Codeberg forge. Hydra change `retire-codeberg-references` owns that.
- Discovery searches (`GithubSearchDiscovery`, `GithubPrivateDiscovery`). They call GitHub too, and the release row already speaks for the connection.
- A manifest page. Versioniq has no `@conduction/nextcloud-vue`, no `CnAppRoot` and no manifest, so the page is a tab in the admin settings page.

## Rollback

Revert the change. Versioniq writes no rows of its own. Integriq removes the rows without a linked source on its next sync. The `connection_report.*` app config keys can stay; nothing else reads them.
