# install-one-click-updates tasks

## 1. One app

- [ ] 1.1 Add `QuickUpdateDialog.vue` with the move, the release notes range and the Update button, and the "Update to {version}" card button fed by `GET /api/updates`. Verify: `QuickUpdateDialog.spec.ts`, and a vitest spec that a pinned app's 409 opens `PinOverrideDialog`.
- [ ] 1.2 Add "Update to safe version {version}" to the advisory line of the card. Verify: a vitest spec that the button calls `requestInstall()` with `recommendedVersion`.

## 2. All apps

- [ ] 2.1 Add `updateBatch.ts` (sequential run, a failure never stops it, stop after the current install). Verify: `updateBatch.spec.ts` with a success, a failure and a stop.
- [ ] 2.2 Add `UpdateAllDialog.vue` and the "Update all ({n})" button, pinned apps unticked with the reason. Verify: `UpdateAllDialog.spec.ts`.
- [ ] 2.3 Add strings to `l10n/en` and `l10n/nl`. Verify: `npm run check:l10n-js`.
- [ ] 2.4 Add `tests/e2e/update-all.spec.ts`: two fixture apps with a newer release on the forge fixture, Update all, both rows read updated, the History tab shows two install rows.

## 3. Close

- [ ] 3.1 Set the matrix rows `ins-upgrade-latest`, `ins-update-all` and `adv-one-click-fix` to `built` with evidence lines, then archive this change.
