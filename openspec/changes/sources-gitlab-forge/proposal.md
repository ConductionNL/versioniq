---
kind: code
---

# Proposal: sources-gitlab-forge

## Why

Public bodies and their suppliers host a lot of Nextcloud apps on GitLab: gitlab.com, and self-managed GitLab at municipalities, provinces and code.overheid-style hosts. Versioniq reads releases from the App Store, GitHub, and a self-hosted Forgejo or Gitea host. An app released on GitLab cannot be bound, listed or installed, so its admin falls back to copying files by hand.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `src-gitlab` | no | GitLab is not a known forge (`lib/Service/Source/ForgeRegistry.php:45-47`, `lib/Service/Source/SourceBinding.php:33-42`). |

### Demand

No demand row. Two competitors are rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- Renovate: "lib/modules/datasource/gitlab-releases, gitlab-tags and gitlab-packages".
- Dependabot: "Core: GitLab is a supported source provider (common/lib/dependabot/source.rb:27) with its tags as release source (release_finder.rb:109)".

## What changes

- A `gitlab` forge: gitlab.com by default, or a self-managed GitLab host the admin sets on the Sources tab, the same way the Forgejo host is set today.
- An admin binds an app to `gitlab:group/project`, nested groups included (`gitlab:group/subgroup/project`). Versions come from the project's releases, release notes from their descriptions, and the archive from a release asset link that matches the asset pattern.
- A GitLab token can be stored like a GitHub token. Versioniq checks its scopes with GitLab's own token endpoint and accepts read-only scopes (`read_api`, `read_repository`) only.
- Trusted-source patterns accept `gitlab:group/*` and deeper globs.
- GitLab has no per-project security advisory API. A GitLab-bound app therefore reads "no advisory source" in the advisory check, never "no advisories".

## Scope

In scope: the forge and its dialect, nested project paths, release listing and asset selection, token validation, trusted patterns, the Sources tab picker, tests.

Out of scope:
- GitLab discovery search. Discovery stays App Store and GitHub, as the Codeberg change decided for its forge.
- GitLab package registries. Releases are how Nextcloud apps are published there.
- Security advisories for GitLab projects: there is no API to read.

## Impact

- Changed: `lib/Service/Source/Forge.php` (a `dialect` field and dialect-specific endpoints), `lib/Service/Source/ForgeRegistry.php` (the `gitlab` forge and its host setting), `lib/Service/Source/SourceBinding.php` (`gitlab` and nested paths), `lib/Service/Source/ForgeReleaseSource.php` (GitLab release and asset shape), `lib/Service/Source/SourceRegistry.php`, `lib/Service/Source/TrustedSourceList.php`, `lib/Service/ExternalReleaseInstallerService.php` (token only to the forge host), `lib/Service/Pat/PatValidator.php`, `lib/Service/Pat/PatDeeplinkBuilder.php`, `lib/Service/Advisory/AdvisoryService.php` (no advisory source), `src/utils/forges.ts`, `src/components/SourcesPanel.vue`, `src/components/TokensPanel.vue`, `l10n`.
- MODIFIED requirement `Forge abstraction` in `external-sources`; ADDED requirements in `external-sources` and `pat-management`.

### MCP coverage

No MCP surface in this change: it adds a source, not an action.

## Rollback

Revert the change. Bindings, tokens and patterns stored under `gitlab` stop resolving and read as an unknown forge, which blocks outbound calls; an admin rebinds those apps.
