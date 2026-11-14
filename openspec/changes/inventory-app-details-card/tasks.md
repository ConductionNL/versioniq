# inventory-app-details-card tasks

## 1. Payload

- [x] 1.1 In `lib/Service/InstallerService.php::getInstalledApps()`, add `author` (via a private `authorName()` helper, no e-mail), `licence` and `links` (`website`, `repository`, `bugs`, http or https only, else `null`). Verify: `tests/unit/Service/InstallerServiceAppListTest.php` covers one author, several authors with mail attributes, a missing `bugs` element and a `javascript:` website.
  > `appAbout()` with `authorName()`, `licenceName()` and `webLink()`; `InstalledAppCard` gains the three fields. `InstallerServiceAppListTest::testTheCardCarriesPublisherLicenceAndSafeLinks`; the app-list golden re-recorded (additions only).
- [x] 1.2 Regenerate `openapi.json` for the new fields. Verify: `composer openapi` leaves no diff after commit.
  > `generate-spec` leaves `openapi.json` unchanged: `GET /api/apps` types its rows as `array<string, mixed>`.

## 2. Page

- [x] 2.1 Add `src/components/AppAboutCard.vue`: publisher, licence, installed version and state, the declared links (new tab, `rel="noopener noreferrer"`), description. Verify: `src/components/AppAboutCard.spec.ts` renders all three links, leaves out a missing one, and shows the enabled and disabled state.
  > `src/components/AppAboutCard.spec.ts` (4 tests).
- [x] 2.2 Extend the `InstalledApp` type in `src/App.vue` and render `AppAboutCard` beside the version list. Verify: `npm run test` passes.
  > Rendered under the selected app, beside the version list; `npm run test`: 64 files, 2964 tests green.
- [x] 2.3 Add the strings to `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js` ("About %s", "Publisher", "Licence", "Installed", "Website", "Source", "Report a problem"). Verify: `npm run check:l10n-js`.
  > "About {app}" (placeholder form) plus Publisher, Licence, Installed, Website, Report a problem, enabled, disabled; "Source" and "Not installed" existed. `.js` regenerated with `scripts/build-l10n-js.cjs`; `check:l10n-js` passes.
- [ ] 2.4 Extend `tests/e2e/version-management.spec.ts`: opening an app's versions shows its licence and a source link.

## 3. Close

- [ ] 3.1 Set the matrix row `inv-app-metadata` to `built` with evidence lines, then archive this change.
