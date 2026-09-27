# admin-translate-apps-tab tasks

## 1. Strings

- [ ] 1.1 Wrap every bare string on the Apps tab in `t()` or `n()`, listed in design Context, and the result grid labels and status values of design D3. Verify: `grep` finds none of the listed English texts outside `t(`, and existing vitest specs still pass.
- [ ] 1.2 Add every used key to `l10n/en.json` and a Dutch value to `l10n/nl.json`, then regenerate the `.js` files. Verify: `npm run check:l10n-js`.

## 2. Check

- [ ] 2.1 Add `scripts/check-l10n-keys.cjs` (missing keys in `en.json` or `nl.json`, bare template text) and run it from `npm run lint`. Verify: the script fails on a scratch file with a bare string and passes on the tree.

## 3. See it

- [ ] 3.1 Add `tests/e2e/dutch.spec.ts`: an admin with language `nl` opens the Apps tab and sees Dutch labels for the dry run switch, the app filter and the result grid, with a screenshot attached for review.

## 4. Close

- [ ] 4.1 Set the matrix row `adm-i18n` to `built` with evidence lines, then archive this change.
