# sources-gitlab-forge tasks

## 1. Forge and binding

- [x] 1.1 Add `dialect` to `Forge` with GitLab endpoints, and the `gitlab` forge and host setting to `ForgeRegistry`. Verify: `tests/unit/Service/Source/ForgeRegistryTest.php` for gitlab.com, a self-managed host, and a refused http host.
- [x] 1.2 Accept `gitlab` bindings with nested paths in `SourceBinding` and `SourceRegistry::parseSourceId()`, refusing `.` and `..` segments and more than 20 segments. Verify: `tests/unit/Service/Source/SourceBindingTest.php` and `SourceRegistryTest.php` with `group/sub/project`, `group/../x` (refused) and a trusted pattern `gitlab:group/*`.
  - `SourceBinding::gitlab()` stores the nested `path` (two to 20 segments in the owner/repo charset, no `.`, `..` or empty segment); `release('gitlab', …)` joins namespace and project into that path; `parseSourceId('gitlab:…')` keeps the whole remainder; `gitlab:group/*` trusts subgroups. Tests that used `gitlab` as the example of an unknown forge now use `bitbucket`. `SourceBindingTest`, `SourceRegistryTest`, `TrustedSourceListTest`.
- [x] 1.3 Send the token only to the forge's own host in `authenticatedDownload()` and the checksum fetch. Verify: a unit test where an asset on another host is fetched without an `Authorization` header.

## 2. Releases

- [x] 2.1 Normalise GitLab releases and asset links in `ForgeReleaseSource`. Verify: a unit test with a recorded GitLab releases payload: versions, changelogs, the selected asset and the sibling checksum.
  - `ForgeReleaseSource::fromGitlabRelease()` maps each GitLab release on read (`description` to the changelog, `released_at` to the date, none for an upcoming release, `assets.links[]` to assets with `direct_asset_url` else `url`), so asset selection, the sibling `.sha256`, digests and the cache are unchanged. `ForgeReleaseSourceGitlabTest` (4, recorded payload).
- [x] 2.2 Treat a forge without an advisories endpoint as "no advisory source" in `AdvisoryService`. Verify: `tests/unit/Service/Advisory/AdvisoryServiceTest.php`.
  - `ForgeReleaseSource::publishesAdvisories()` is false for a GitLab binding; `AdvisoryService::correlate()` then checks only the central feed and stores "No advisory source: gitlab publishes no repository security advisories." as the entry's reason, so the app never reads as clean. `AdvisoryServiceTest`.

## 3. Tokens

- [x] 3.1 Validate GitLab tokens through `personal_access_tokens/self`, and add the deeplink. Verify: `tests/unit/Service/Pat/PatValidatorTest.php` accepts `read_api`, rejects `api`, and captures `expires_at`.
  - `PatValidator` reads `personal_access_tokens/self` (scopes limited to `read_api` and `read_repository`, `expires_at` kept; `PatValidatorTest`). `PatDeeplinkBuilder` opens GitLab's token page on gitlab.com or the self-managed host with `name` and `scopes=read_api` filled in (`PatDeeplinkBuilderTest`, 2 new).

## 4. Pages

- [x] 4.1 Add GitLab to `forges.ts`, the host field and picker on `SourcesPanel.vue`, and the token forge on `TokensPanel.vue`. Verify: `ForgePickers.spec.ts` and `SourcesPanel.spec.ts`.
  - `forges.ts` offers GitLab on the Sources, Tokens and Trusted sources pickers. `SourcesPanel.vue` asks for a group or namespace and a project when GitLab is the forge, and has a GitLab host section (empty is gitlab.com) on the new admin-only `GET`/`PUT /api/forges/gitlab` (`ForgeController::gitlabHost()`/`setGitlabHost()`, `ForgeControllerTest` 2 new). `ForgePickers.spec.ts` (3 new).
- [x] 4.2 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
  - 10 new strings in en and nl, the Sources hint reworded to name GitLab; `check:l10n-js` up to date.
- [ ] 4.3 Add a GitLab-shaped fixture to `tests/e2e/fixtures/forge` and `tests/e2e/gitlab.spec.ts`: bind `gitlab:group/sub/app`, list versions, install one.

## 5. Close

- [ ] 5.0 Record the self-hosted Forgejo requirement for the matrix row `src-codeberg`. The behaviour is built (`lib/Controller/ForgeController.php`, `lib/Service/Source/ForgeRegistry.php`); this change only writes it down.

- [ ] 5.1 Set the matrix row `src-gitlab` to `built` with evidence lines, then archive this change.
