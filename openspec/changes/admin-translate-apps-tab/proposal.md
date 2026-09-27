---
kind: code
---

# Proposal: admin-translate-apps-tab

## Why

A Dutch admin who opens Versioniq reads half of the Apps tab in Dutch and half in English. The labels that decide what an install does are the English half: "Dry run (evaluate the install, apply no changes)", "Pick an installed App", "Choose app", "Selected version", the result grid and the downgrade warning. Tenders for Dutch public bodies ask for a Dutch interface, and Versioniq already ships a Dutch translation; the strings just never reach it.

This change comes from the competitor parity matrix `openspec/parity/capabilities.json` (versioniq, compared 2026-09-26) and covers one row.

| Row | Rating now | What is missing |
|---|---|---|
| `adm-i18n` | partial, built | The missing half: a large part of the Apps tab is hardcoded English, outside `t()`, so no translation can reach it. |

### Demand

No demand row. Two competitors are rated yes.

### Competitors rated yes (evidence quoted from the matrix)

- Nextcloud: "apps/appstore/l10n holds 76 language files including nl.json".
- Easy Updates Manager: "All strings use the stops-core-theme-and-plugin-updates text domain (languages/stops-core-theme-and-plugin-updates.pot); Dutch and German are 100% translated on translate.wordpress.org (https://translate.wordpress.org/projects/wp-plugins/stops-core-theme-and-plugin-updates/)".

## What changes

- Every user-facing string on the Apps tab goes through `t()` or `n()`: labels, placeholders, buttons, tooltips, the result grid, the downgrade warning and the empty states.
- `l10n/en.json` gets every key the code uses, and `l10n/nl.json` a Dutch translation for each. Today `en.json` has 256 keys and `nl.json` 283; the code uses keys that `en.json` lacks.
- A check in `npm run lint` fails when a template holds a bare English text node, or when a key the code uses is missing from `en.json` or `nl.json`, so the gap cannot come back.

## Scope

In scope: the Apps tab strings in `src/App.vue` and the components it renders, the two catalogues, the lint check, tests.

Out of scope:
- Other languages than Dutch and English. The catalogues are ready for them; translating is a separate job.
- Server-side messages that the API returns in English (failure messages, hints). They already pass through the server's `IL10N` where the installers build them; a row about them would be a new row.

## Impact

- Changed: `src/App.vue`, `src/components/*.vue` that render on the Apps tab, `l10n/en.json`, `l10n/nl.json` and their generated `.js` files, `package.json` (the check in `lint`).
- New: `scripts/check-l10n-keys.cjs`.
- New capability spec `interface-language`.

### MCP coverage

No MCP surface in this change: it translates existing text.

## Rollback

Revert the change. The page falls back to its English strings.
