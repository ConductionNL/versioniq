import { defineConfig, devices } from '@playwright/test'
import { BASE_URL } from './tests/e2e/base-url.ts'

/**
 * Playwright end-to-end configuration for Versioniq.
 *
 * The suite drives the real admin UI against a running Nextcloud instance with
 * this app enabled. Point it at a disposable instance (see docs/e2e.md) — the
 * specs create and clean up their own state (pins, policies), but they operate
 * on a live instance and must never be aimed at production.
 *
 * Base URL and credentials come from the environment so the same suite runs
 * against a throwaway container locally and against CI's instance later:
 *   NC_BASE_URL   (default http://localhost:8099)
 *
 * The target itself is resolved in tests/e2e/base-url.ts, which refuses the
 * shared development instance on localhost:8080 unless the run names it in
 * VERSIONIQ_E2E_ALLOW_SHARED_INSTANCE. Port 8099 is a rig of this suite's own
 * and needs no flag.
 *   NC_ADMIN_USER (default admin)
 *   NC_ADMIN_PASS (default adminadmin123)
 */
export const NC_BASE_URL = BASE_URL
export const NC_ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
export const NC_ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'adminadmin123'

/** Where the logged-in admin session is persisted by `auth.setup.ts`. */
export const AUTH_FILE = 'tests/e2e/.auth/admin.json'

export default defineConfig({
	testDir: './tests/e2e',
	// Playwright's default testMatch takes `*.test.ts` as well as `*.spec.ts`,
	// and `tests/e2e/shared-instance.test.ts` is a vitest spec that happens to
	// live in this directory. Collected by playwright it throws at import and
	// takes the WHOLE listing down with it: measured here, the suite reported
	// `Total: 0 tests in 0 files` rather than any error a reader would connect
	// to that file. Every e2e spec in this app is `*.spec.ts`, so ignoring
	// `*.test.ts` states the split rather than naming one file.
	testIgnore: ['**/*.test.ts'],
	// The admin settings page is a single shared surface and several specs mutate
	// server-side state (pins, policies, cache). Serial execution keeps them from
	// racing each other on the same instance.
	fullyParallel: false,
	workers: 1,
	forbidOnly: !!process.env.CI,
	// One retry everywhere. The forge specs mutate shared server-side state (the
	// fixture app's installed version, recorded digests) on a single instance
	// they run serially against; a retry re-runs a flaked test from a clean
	// beforeEach. Each such test is verified to pass in isolation, so a retry
	// recovers from state-isolation races, not from a product defect.
	retries: 1,
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
	timeout: 60_000,
	expect: { timeout: 15_000 },
	use: {
		baseURL: NC_BASE_URL,
		// A cold Nextcloud (empty opcache) can take ~30s to serve the first page,
		// which is exactly the default navigation timeout — be generous so a cold
		// instance is slow rather than flaky.
		navigationTimeout: 90_000,
		actionTimeout: 20_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		ignoreHTTPSErrors: true,
	},
	projects: [
		// Logs in once and persists the session for every other project. It must
		// run without a stored session, so `storageState` is set on the consuming
		// project rather than globally (an inherited `undefined` still resolves to
		// the parent value and would make setup fail on a missing state file).
		{ name: 'setup', testMatch: /auth\.setup\.ts/ },
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'], storageState: AUTH_FILE },
			dependencies: ['setup'],
		},
	],
})
