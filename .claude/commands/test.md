---
description: Run the PHP and frontend unit tests
---

Run the unit tests from the repository root and report the totals and any failures. Install dependencies first if `vendor/` or `node_modules/` is missing (`composer install`, `npm ci`).

1. `composer test:unit-only` runs PHPUnit with `tests/phpunit-unit-only.xml` against mocked OCP interfaces; it needs no Nextcloud server.
2. `npm test` runs the Vitest suite.

`composer test:unit` runs the full suite from `phpunit.xml`, but `tests/bootstrap.php` loads the Nextcloud server's own `tests/bootstrap.php`, so it only works when this app sits inside a server checkout (as it does in CI). Do not treat its bootstrap error outside a server as a test failure.

To run a single PHP test: `./vendor/bin/phpunit -c tests/phpunit-unit-only.xml --filter <TestName>`. To run a single Vitest file: `npx vitest run <path>`. $ARGUMENTS can name a test or path to narrow the run.
