---
description: Run the PHP and frontend quality checks and report what fails
---

Run the quality suite from the repository root and report the result of each step. Install dependencies first if `vendor/` or `node_modules/` is missing (`composer install`, `npm ci`).

1. `composer check:strict` runs `composer lint` (php -l), `composer cs:check` (php-cs-fixer dry run) and `composer psalm`, and prints which of them failed.
2. `npm run lint` runs ESLint on `src`, `tests` and `scripts`, then `scripts/check-l10n-keys.cjs`.
3. `npm run stylelint` checks `src/**/*.{vue,scss,css}`.
4. `npm run check:l10n-js` fails when `l10n/*.js` is stale; fix it with `npm run l10n:build`.

To fix PHP style, run `composer cs:fix`. When reporting, separate findings on lines changed in this branch from findings that already exist on `development` ($ARGUMENTS can name files to focus on).
