# Design: sources-gitlab-forge

Read against `development` at 02e1050 (2026-09-27).

## Context

- `ForgeRegistry` (`lib/Service/Source/ForgeRegistry.php:45-221`) registers `github`, the retired `codeberg`, and `forgejo` once a host is set (`registerForgejo()`, line 100; `setForgejoHost()`, line 197, https only, no credentials).
- `Forge` (`lib/Service/Source/Forge.php:31-70`) builds endpoints with GitHub and Forgejo paths: `/repos/{owner}/{repo}/releases`, `/user`, `/repos/{owner}/{repo}/security-advisories`. The class comment says a new forge is a config entry, not a class. GitLab's API is shaped differently (`/api/v4/projects/{url-encoded path}/releases`, a `PRIVATE-TOKEN` header or Bearer), so a config entry alone cannot carry it.
- `SourceBinding` (`lib/Service/Source/SourceBinding.php:59-90`) requires an `owner` and a `repo` that each match `^[A-Za-z0-9_.\-]+$`, a path-traversal guard (CWE-22), and lists the allowed forges in `FORGES` (line 33-42).
- `ForgeReleaseSource::performFetch()` (`lib/Service/Source/ForgeReleaseSource.php:307-383`) sets GitHub headers, maps 401, 403 and 404 to messages, and expects a JSON list. `buildReleasePayload()` (line 385) reads `assets[].name` and `browser_download_url`. GitLab releases carry `tag_name`, `description`, `released_at` and `assets.links[]` with `name` and `direct_asset_url`.
- `SourceRegistry` parses a source id by splitting on the first `/` into owner and repo (`lib/Service/Source/SourceRegistry.php:85-100`), so `gitlab:group/sub/project` would read as owner `group`, repo `sub/project` and fail the character check.
- `ExternalReleaseInstallerService::authenticatedDownload()` sends `Authorization: Bearer <token>` to whatever URL the release names (`lib/Service/ExternalReleaseInstallerService.php:433-455`, and the checksum fetch at line 479). A GitLab asset link may point at another host.
- `PatValidator::validate()` (`lib/Service/Pat/PatValidator.php:70-160`) probes the user endpoint and reads GitHub's `X-OAuth-Scopes`; forges without a scope header are accepted with an `unverifiable_scope` warning. GitLab exposes a token's scopes and expiry at `GET /api/v4/personal_access_tokens/self`.
- The current spec (`openspec/specs/external-sources/spec.md`, requirement "Forge abstraction") has a scenario that `get('gitlab')` must throw.

## Goals and non-goals

Goals: bind, list, install and authenticate against GitLab releases with the same guards as GitHub.

Non-goals: GitLab discovery, package registries, advisories.

## Decisions

### D1. A dialect on `Forge`

`Forge` gains `dialect` (`github` or `gitlab`). `releasesEndpoint()`, `userEndpoint()`, `tokenInfoEndpoint()` and `advisoriesEndpoint()` switch on it; GitHub and Forgejo keep today's paths, GitLab gets `/projects/{rawurlencode(path)}/releases?per_page=100`, `/user`, `/personal_access_tokens/self`, and no advisories endpoint (null). `authHeaderValue()` stays as is, with GitLab on the Bearer scheme, which GitLab accepts for personal access tokens.

Alternative considered: a separate `GitlabReleaseSource`. Rejected: the pin, allowlist, checksum and cache logic in `ForgeReleaseSource` would be duplicated; only the request and the response shape differ.

### D2. The `gitlab` forge and its host

`ForgeRegistry` registers `gitlab` with API `https://gitlab.com/api/v4`, web `https://gitlab.com` and token page `https://gitlab.com/-/user_settings/personal_access_tokens`. `setGitlabHost()` mirrors `setForgejoHost()`: https only, no credentials, query or fragment, and a stale `api_base` override is dropped. The Sources tab gets the same host field.

### D3. Nested project paths, still traversal-safe

A `gitlab` binding stores `path` (for example `group/subgroup/project`) instead of `owner` and `repo`. Each segment must match the existing character class, there are at most 20 segments, and `.` and `..` segments are refused, so the CWE-22 guard holds. `getOwnerRepo()` returns the full path for GitLab, and the source id is `gitlab:{path}`. Trusted patterns match with `fnmatch` as today, so `gitlab:group/*` allows every project below `group`.

`SourceRegistry::parseSourceId()` keeps the whole remainder as the path for a `gitlab:` id instead of splitting it into owner and repo.

### D4. Releases and assets

`ForgeReleaseSource` normalises GitLab releases into the shape it already handles: `tag_name` as version, `description` as changelog, and `assets.links[]` mapped to `{name, url: direct_asset_url ?? url}`. Asset selection, the sibling `.sha256` link, recorded digests and the artifact cache then work unchanged. A release without a matching link is skipped, as today.

The token goes only to the forge's own host. `authenticatedDownload()` and the checksum fetch add the `Authorization` header only when the URL's host equals the host of the forge's web or API base; an asset link on another host is fetched without it. This applies to every forge, so it also closes the same leak for GitHub and Forgejo release assets that redirect or link elsewhere.

### D5. Tokens

`PatValidator` for `gitlab` calls `personal_access_tokens/self`, reads `scopes` and `expires_at`, accepts only `read_api` and `read_repository`, and rejects any token that also carries `api`, `write_repository` or `sudo`. `PatDeeplinkBuilder` opens GitLab's token page with the name and the `read_api` scope filled in. `PatResolver` already filters tokens by forge.

### D6. Advisories

`AdvisoryService` treats a forge with no advisories endpoint as "no advisory source" for that app, stored with a reason the Advisories tab shows. It never reports such an app as clean.

## Risks and trade-offs

- [GitLab rate limits anonymous API calls] → the error mapping names the limit and suggests a token, as for GitHub.
- [Nested paths widen what a pattern matches] → `gitlab:group/*` matching subgroups is the intended GitLab meaning; the Sources tab shows the matched source id before binding.
- [A GitLab release links an asset on another host] → the download goes through the same client with `allow_local_address` off, and the checksum rules apply.

## Migration

No schema change: the `pats.forge` column already takes any forge id. Rollback: revert; rebind affected apps.
