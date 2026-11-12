# advisories-name-their-feeds tasks

## 1. Record

- [x] 1.1 Read the advisory sources against `development`: `NextcloudAdvisoryFeed`, `ForgeReleaseSource::listAdvisories()` and `AdvisoryService::correlate()`.
- [x] 1.2 Point `adv-nextcloud-feed` and `adv-github-advisories` in `capabilities.json` at this change.

## 2. Tests

- [ ] 2.1 Confirm a unit test covers each new scenario, and add the missing one: the central feed for an App Store app (`tests/unit/Service/Advisory/AdvisoryServiceTest.php`, `testAnAppStoreAppIsCorrelatedFromTheCentralFeed`) and the repository advisories of a GitHub-bound app (`tests/unit/Service/Source/ForgeReleaseSourceTest.php`). Verify: `composer test:unit -- --filter 'Advisory'`.

## 3. Close

- [ ] 3.1 Archive this change after `codeberg-is-no-release-source`, so the main spec drops Codeberg in one pass.
