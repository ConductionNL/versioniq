# adopt-connection-registry tasks

## 1. Declare

- [x] 1.1 Write `lib/Settings/connections.json` with `appstore`, `github` and `advisories`.
- [x] 1.2 Give the App sources, Security advisory checks and Integrations headings the ids the file links to, and open the matching tab from the hash.
- [x] 1.3 Guard the file in `tests/unit/Settings/ConnectionsDeclarationTest.php`.

## 2. Page

- [x] 2.1 Add `src/components/IntegrationsPanel.vue` and the Integrations tab in `src/App.vue`.
- [x] 2.2 Add `src/utils/connectionRegistry.ts` with the two formatters, the Add integration URL and the row filter.
- [x] 2.3 Provide `integriq-installed` from `lib/Settings/Admin.php`.
- [x] 2.4 Add the strings to `l10n/en` and `l10n/nl`.
- [x] 2.5 Cover it in `src/utils/connectionRegistry.spec.ts` and `src/components/IntegrationsPanel.spec.ts`.

## 3. Reports and refresh

- [x] 3.1 Add `lib/Service/Connection/ConnectionReportService.php`.
- [x] 3.2 Report GitHub answers from `ForgeReleaseSource`.
- [x] 3.3 Report catalogue fetches from `AppStoreSource`.
- [x] 3.4 Report advisory checks from `NextcloudAdvisoryFeed`.
- [x] 3.5 Refresh and report from the token save and removal in `ApiController`.
- [x] 3.6 Add the integriq event stubs for PHPUnit and psalm.
- [x] 3.7 Cover it in unit tests.

## 4. End to end

- [x] 4.1 Write `tests/e2e/integrations.spec.ts`.
- [x] 4.2 Install openregister and integriq in the CI `additional-apps`.

## 5. After integriq ships

- [ ] 5.1 Run the e2e spec against an instance with both apps, then archive this change.
