---
description: Run the Playwright end-to-end suite against a disposable Nextcloud
---

The Playwright suite in `tests/e2e/` drives the real admin UI and writes to the instance it is given. Never point it at production.

1. Make sure a disposable instance with Versioniq enabled is running. `docs/e2e.md` has the exact steps (a `nextcloud` container on port 8099, the app copied in and enabled, caches warmed). The forge specs also need `tests/e2e/fixtures/forge/bootstrap.sh <container>`.
2. Set `NC_BASE_URL`, `NC_ADMIN_USER` and `NC_ADMIN_PASS` if the instance differs from the defaults in `playwright.config.ts` (`http://localhost:8099`, `admin`, `adminadmin123`).
3. Run `npm run test:e2e`, or `npx playwright test <spec>` for one spec ($ARGUMENTS). `npm run test:e2e:ui` opens Playwright UI mode.

Report passed, failed and flaky tests. The suite runs serially (`workers: 1`) with one retry.
