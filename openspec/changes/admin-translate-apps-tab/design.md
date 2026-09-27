# Design: admin-translate-apps-tab

Read against `development` at 02e1050 (2026-09-27).

## Context

- `src/App.vue` calls `t('versioniq', ...)` 60 times, but the Apps tab still renders bare English in many places, for example `src/App.vue:2069` ("Dry run (evaluate the install, apply no changes)"), 2077 ("Show install debug output"), 2177 ("Pick an installed App"), 2183 ("Hide filters" and "Show filters"), 2188 ("Core apps"), 2199 ("Search apps"), 2218 ("CORE"), 2297 ("Loading..." and "Choose app"), 2320 ("No apps match your filter."), 2388 ("Selected version"), 2426 ("Filter versions"), 2490 ("Pick other"), 2549-2569 (the result grid) and 2589 ("No details").
- `l10n/en.json` has 256 keys and `l10n/nl.json` 283. Some keys the code uses are missing from `en.json`, which ADR-007 names as the source of truth for keys.
- `scripts/build-l10n-js.cjs` generates `l10n/<locale>.js` from the JSON, and `npm run check:l10n-js` asserts the pair matches. Nothing checks that every `t()` key is in `en.json` and `nl.json`, or that a template has no bare text.
- Hydra ADR-057 (i18n locale parity and key hygiene) describes exactly this drift across the fleet: checks that validate source keys and not target parity.

## Goals and non-goals

Goals: every Apps tab string translatable, Dutch for all of them, and a check that keeps it that way.

Non-goals: more languages, server-side messages.

## Decisions

### D1. English source keys, Dutch values

Every string becomes `t('versioniq', '<English sentence>')` with the English text as the key (ADR-007), plural counts through `n()`. `CORE` becomes `t('versioniq', 'Core')` in sentence case, shown in capitals by CSS if the design wants it, so the Dutch reads "Kern" and not a shouted English word.

### D2. One key check

`scripts/check-l10n-keys.cjs` reads every `.vue` and `.ts` file under `src/`, collects the literal first arguments of `t('versioniq', ...)` and `n('versioniq', ...)`, and fails when a key is missing from `l10n/en.json` or `l10n/nl.json`. It also fails on a template text node with two or more letters outside `{{ }}` that is not inside `t()`, with an allow-list for symbols and app ids. `npm run lint` runs it after eslint.

Alternative considered: an eslint plugin rule. Rejected: the Vue template parser in the current eslint setup does not flag bare text, and a small script is easier to read than a plugin.

### D3. The result grid

The install result grid labels (`src/App.vue:2549-2569`) are fixed column names ("App", "From", "To", "Mode", "Status", "Category", "Stage"). They become keys, and the status values Versioniq itself produces (Done, Dry run, Reverted, Installed but broken, Failed) are mapped through `t()` in `src/utils/installResult.ts`, which already builds them.

## Risks and trade-offs

- [A Dutch string that is too long breaks a card layout] → the e2e run below takes screenshots in Dutch for review.
- [The bare-text check flags a legitimate symbol] → an allow-list in the script, reviewed like code.

## Migration

None. Rollback: revert.
