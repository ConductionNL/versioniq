# sources-gitlab-forge tasks

## 1. Forge and binding

- [ ] 1.1 Add `dialect` to `Forge` with GitLab endpoints, and the `gitlab` forge and host setting to `ForgeRegistry`. Verify: `tests/unit/Service/Source/ForgeRegistryTest.php` for gitlab.com, a self-managed host, and a refused http host.
- [ ] 1.2 Accept `gitlab` bindings with nested paths in `SourceBinding` and `SourceRegistry::parseSourceId()`, refusing `.` and `..` segments and more than 20 segments. Verify: `tests/unit/Service/Source/SourceBindingTest.php` and `SourceRegistryTest.php` with `group/sub/project`, `group/../x` (refused) and a trusted pattern `gitlab:group/*`.
- [ ] 1.3 Send the token only to the forge's own host in `authenticatedDownload()` and the checksum fetch. Verify: a unit test where an asset on another host is fetched without an `Authorization` header.

## 2. Releases

- [ ] 2.1 Normalise GitLab releases and asset links in `ForgeReleaseSource`. Verify: a unit test with a recorded GitLab releases payload: versions, changelogs, the selected asset and the sibling checksum.
- [ ] 2.2 Treat a forge without an advisories endpoint as "no advisory source" in `AdvisoryService`. Verify: `tests/unit/Service/Advisory/AdvisoryServiceTest.php`.

## 3. Tokens

- [ ] 3.1 Validate GitLab tokens through `personal_access_tokens/self`, and add the deeplink. Verify: `tests/unit/Service/Pat/PatValidatorTest.php` accepts `read_api`, rejects `api`, and captures `expires_at`.

## 4. Pages

- [ ] 4.1 Add GitLab to `forges.ts`, the host field and picker on `SourcesPanel.vue`, and the token forge on `TokensPanel.vue`. Verify: `ForgePickers.spec.ts` and `SourcesPanel.spec.ts`.
- [ ] 4.2 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 4.3 Add a GitLab-shaped fixture to `tests/e2e/fixtures/forge` and `tests/e2e/gitlab.spec.ts`: bind `gitlab:group/sub/app`, list versions, install one.

## 5. Close

- [ ] 5.1 Set the matrix row `src-gitlab` to `built` with evidence lines, then archive this change.
