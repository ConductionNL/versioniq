import type { Page } from "@playwright/test";

import { expect } from "@playwright/test";
import { execFile } from "node:child_process";
import { existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);

export const SETTINGS_URL = "/index.php/settings/admin/versioniq";

/** Tab labels as rendered in the Versioniq settings tablist. */
export type TabName =
	| "Apps"
	| "History"
	| "Sources"
	| "Tokens"
	| "Trusted sources"
	| "Discover"
	| "Artifact cache";

/**
 * Opens the admin settings page and waits for the app shell to be interactive.
 *
 * `waitUntil: 'domcontentloaded'` is load-bearing. The default is `'load'`,
 * which waits for every sub-resource — and a Nextcloud settings page keeps
 * requests in flight, so `load` does not fire. The navigation then sat until
 * the 60s test timeout killed it, and Playwright reported the kill as
 *
 *   page.goto: net::ERR_ABORTED; maybe frame was detached?
 *
 * which reads like the PAGE broke. It did not: nothing was ever waiting for
 * the page, only for an event the platform does not emit (the same defect
 * ADR-074 rule 4 names for `networkidle`).
 *
 * Nothing is lost by not waiting for `load`: the two assertions below are the
 * real readiness signal, and they are what the callers actually depend on.
 */
export async function openSettings(page: Page): Promise<void> {
	await page.goto(SETTINGS_URL, { waitUntil: "domcontentloaded" });
	await expect(
		page.getByRole("heading", { name: "Versioniq", level: 2 }),
	).toBeVisible();
	await expect(
		page.getByRole("tablist", { name: "Versioniq sections" }),
	).toBeVisible();
}

/** Switches to a top-level tab and returns its panel locator. */
export async function openTab(page: Page, tab: TabName) {
	await page
		.getByRole("tablist", { name: "Versioniq sections" })
		.getByRole("tab", { name: tab, exact: true })
		.click();
	const panel = page.getByRole("tabpanel", { name: tab });
	await expect(panel).toBeVisible();
	return panel;
}

/**
 * Opens the version picker for an app by its appId, from the Apps tab.
 * Returns once the app-detail header shows the selected app.
 */
export async function chooseApp(page: Page, appId: string): Promise<void> {
	await openTab(page, "Apps");
	const card = page
		.locator("article")
		.filter({ has: page.getByText(appId, { exact: true }) })
		.first();
	await card.getByRole("button", { name: "Choose app" }).click();
	await expect(page.getByText("Selected app")).toBeVisible();
	await expect(
		page.getByRole("button", { name: "Choose another app" }),
	).toBeVisible();
}

/**
 * Waits for the version list of the selected app to finish loading.
 * Returns true when versions rendered, false when the source reported none
 * (offline / not on the App Store) so a spec can skip network-dependent asserts.
 *
 * 🔑 A WAIT LONGER THAN ITS TEST'S TIMEOUT CAN NEVER ELAPSE. This waited
 * 240_000ms inside a test bounded at 60_000 (playwright.config.ts), so it could
 * only ever use a quarter of its stated patience — and when loading did stall,
 * the failure read `Test timeout of 60000ms exceeded` rather than naming the
 * thing that never finished. Same arithmetic-opposition defect as a bounded
 * apt retry inside a shorter job cap (ConductionNL/.github#510).
 *
 * 240s was calibrated for the real App Store (~12.4 MB from garm3). The fixture
 * now serves that catalogue locally, so the budget belongs inside the test
 * bound, where it can actually be spent. A spec that legitimately needs longer
 * should raise its own timeout with `test.setTimeout()` — deliberately, and
 * visibly, rather than inheriting a number that silently cannot apply.
 */
export async function versionsLoaded(page: Page): Promise<boolean> {
	const loading = page.getByText(
		"Fetching available versions from the source",
	);
	await expect(
		loading,
		"the version list never finished loading — the source did not answer",
	).toBeHidden({ timeout: 45_000 });
	const rows = page.getByTestId("changelog-toggle");
	return (await rows.count()) > 0;
}

/**
 * Reads an app config value straight from the server, to assert persistence.
 *
 * ⚠️ THROWS when the READ itself fails, and it must. This previously did
 * `if (!res.ok()) return null`, which is the same defect #233 fixed in
 * `runJob`: a broken fixture wearing the words of a failed assertion.
 *
 * Every one of the eight specs failing on development goes through here, and
 * every one of them fails as though the FEATURE did nothing:
 *
 *     expect(pin.driftedTo ?? …, 'drift recorded on the pin').toBeTruthy()
 *     expect(binding.forge).toBe('codeberg')          // Received: undefined
 *     expect(binding.sha256?.['1.0.1'], …).toMatch(…) // Received: undefined
 *
 * A null read and a feature that wrote nothing are indistinguishable once the
 * value reaches `JSON.parse(… ?? '{}')`, so all eight blame the app for what
 * may be a transport, auth, or provisioning_api problem. They need different
 * fixes, so they must not wear the same words.
 *
 * Worse, the sibling test that asserts an ABSENCE — "records no drift while
 * the installed version matches the pin" — PASSES on a null read, because a
 * read that returned nothing looks exactly like a job that recorded nothing.
 * That is the failure mode #233's own docblock warns about, one helper over.
 *
 * A genuinely unset key is not an error: OCS answers 200 with an ocs.meta
 * statuscode of 404, and that still returns null.
 */
/**
 * 5xx from this endpoint is TRANSIENT, and treating it as fatal is its own kind
 * of wrong answer.
 *
 * Measured on run 33073... of this branch: the loud error above fired five
 * times and every one of those specs PASSED on retry — nine flaky specs in a
 * single run, all of them this same read. Nextcloud answers 503 while an app
 * install or upgrade is in flight, which is exactly when these fixtures run.
 *
 * So retry the 5xx band, briefly and boundedly. A persistent outage still
 * throws with the same words; what stops is a spec being marked flaky because
 * the server was mid-install for 200ms. 4xx is NOT retried — an auth or
 * permission failure is a real answer and repeating it only hides it.
 */
const CONFIG_READ_ATTEMPTS = 4;

export async function appConfigValue(
	page: Page,
	key: string,
): Promise<string | null> {
	const url = `/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/versioniq/${key}?format=json`;
	let res = await page.request.get(url, {
		headers: { "OCS-APIRequest": "true" },
	});
	for (
		let attempt = 2;
		attempt <= CONFIG_READ_ATTEMPTS && res.status() >= 500;
		attempt++
	) {
		await page.waitForTimeout(250 * (attempt - 1));
		res = await page.request.get(url, {
			headers: { "OCS-APIRequest": "true" },
		});
	}
	if (!res.ok()) {
		const transient = res.status() >= 500;
		throw new Error(
			`appConfigValue(${key}): the config READ failed with HTTP ${res.status()}` +
				(transient ? ` after ${CONFIG_READ_ATTEMPTS} attempts` : "") +
				". The value was never retrieved, so nothing can be concluded about whether " +
				"the app persisted it. This is a broken fixture, not a failing assertion — " +
				(transient
					? "a 5xx that survives four attempts is an unhealthy server, not a race — " +
						"check the instance came up and is not stuck in maintenance mode. "
					: "check that provisioning_api is enabled and that the request is authenticated. ") +
				`URL: ${url}`,
		);
	}
	const body = await res.json();
	const status = body?.ocs?.meta?.statuscode;
	// 404 here means the key is genuinely unset, which is a real answer.
	if (
		status !== undefined &&
		status !== 100 &&
		status !== 200 &&
		status !== 404
	) {
		throw new Error(
			`appConfigValue(${key}): OCS returned statuscode ${status} ` +
				`(${body?.ocs?.meta?.message ?? "no message"}). The read did not succeed.`,
		);
	}
	const data = body?.ocs?.data?.data;
	return typeof data === "string" && data !== "" ? data : null;
}

// --- Forge fixture ---------------------------------------------------------
// The fixture forge (tests/e2e/fixtures/forge) must be bootstrapped before the
// forge specs run (see docs/e2e.md and fixtures/forge/bootstrap.sh). These
// helpers drive its control plane and the app's install API.

/** Base URL of the fixture forge's control plane, as reachable from the host. */
export const FIXTURE_URL =
	process.env.FORGE_FIXTURE_URL ?? "http://localhost:9099";

/** The app installed from the fixture forge, and the source it is bound to. */
export const FIXTURE_APP = "fixtureapp";
export const FIXTURE_SOURCE = "codeberg:fixtureowner/fixtureapp";

/** Whether the fixture forge is reachable — forge specs skip when it is not. */
export async function fixtureAvailable(page: Page): Promise<boolean> {
	try {
		const res = await page.request.get(`${FIXTURE_URL}/health`, {
			timeout: 5_000,
		});
		return res.ok();
	} catch {
		return false;
	}
}

/** Resets the fixture forge to its default release set and clears overrides. */
export async function resetFixture(page: Page): Promise<void> {
	await page.request.post(`${FIXTURE_URL}/control/reset`);
}

/** Posts a control command to the fixture forge. */
export async function fixtureControl(
	page: Page,
	path: string,
	body: unknown,
): Promise<void> {
	const res = await page.request.post(`${FIXTURE_URL}/control/${path}`, {
		data: body as object,
	});
	if (!res.ok()) {
		throw new Error(`fixture control ${path} failed: ${res.status()}`);
	}
}

/**
 * How to reach the instance: a Docker container locally, or the runner's own
 * filesystem in CI.
 *
 * ⚠️ EVERY `occ` AND `sql` HELPER USED TO SHELL OUT TO `docker exec av-e2e`,
 * and both swallowed the failure into an empty string. In CI there is no such
 * container — the shared quality.yml runs the PHP built-in server on the runner
 * — so every one of those calls failed silently and the specs that depend on
 * them failed on opaque assertions (`Expected: 0, Received: 1`) that named
 * neither Docker nor the missing container.
 *
 * The mode is DETECTED rather than configured, so neither the developer setup
 * nor the shared workflow needs a new environment variable: Playwright runs
 * with cwd inside the app, which in CI sits at `<server>/apps/versioniq`, so
 * walking up for an `occ` file finds the server root. A developer checkout is
 * not inside a Nextcloud tree, finds nothing, and keeps the Docker behaviour.
 * An explicit `NC_CONTAINER` or `NC_SERVER_ROOT` overrides the detection.
 */
type Instance =
	{ mode: "docker"; container: string } | { mode: "local"; root: string };

function resolveInstance(): Instance {
	if (process.env.NC_CONTAINER) {
		return { mode: "docker", container: process.env.NC_CONTAINER };
	}
	if (process.env.NC_SERVER_ROOT) {
		return { mode: "local", root: process.env.NC_SERVER_ROOT };
	}

	let dir = process.cwd();
	for (let i = 0; i < 6; i++) {
		if (existsSync(join(dir, "occ"))) {
			return { mode: "local", root: dir };
		}
		const up = dirname(dir);
		if (up === dir) break;
		dir = up;
	}

	return { mode: "docker", container: "av-e2e" };
}

export const INSTANCE = resolveInstance();

/**
 * Runs a command against the instance, wherever it lives.
 *
 * Returns the exit code alongside the output instead of throwing, because
 * several specs assert on a NON-ZERO exit (a refused downgrade, an integrity
 * failure) — that is the behaviour under test, not an error. `stderr` is
 * returned too: the previous helpers discarded it, which is how "docker: not
 * found" became an empty string and then a confusing assertion.
 */
export async function execInInstance(
	argv: string[],
	opts: { asRoot?: boolean; env?: Record<string, string> } = {},
): Promise<{ code: number; stdout: string; stderr: string }> {
	let cmd: string;
	let args: string[];
	let spawnOpts: Record<string, unknown>;

	if (INSTANCE.mode === "docker") {
		const envArgs = Object.entries(opts.env ?? {}).flatMap(([k, v]) => [
			"-e",
			`${k}=${v}`,
		]);
		cmd = "docker";
		args = [
			"exec",
			...envArgs,
			"-u",
			opts.asRoot ? "root" : "www-data",
			INSTANCE.container,
			...argv,
		];
		spawnOpts = {};
	} else {
		// On a runner the tests own the tree, so `asRoot` has nothing to grant
		// and is deliberately a no-op rather than a sudo escalation.
		cmd = argv[0];
		args = argv.slice(1);
		spawnOpts = {
			cwd: INSTANCE.root,
			env: { ...process.env, ...(opts.env ?? {}) },
		};
	}

	try {
		const { stdout, stderr } = await execFileAsync(cmd, args, {
			maxBuffer: 8 * 1024 * 1024,
			...spawnOpts,
		});
		return { code: 0, stdout, stderr };
	} catch (err) {
		const e = err as {
			code?: number;
			stdout?: string;
			stderr?: string;
			message?: string;
		};
		return {
			code: typeof e.code === "number" ? e.code : 1,
			stdout: e.stdout ?? "",
			stderr: e.stderr ?? e.message ?? "",
		};
	}
}

/**
 * A `php -r` snippet that opens the instance's OWN database.
 *
 * The previous helpers hard-coded `sqlite:/var/www/html/data/nc.db.db`, which
 * is right for the Docker image and wrong everywhere else — CI runs pgsql, so
 * every query would have failed even once the container problem was fixed.
 * Reading `config/config.php` means the helper follows the instance rather than
 * a remembered layout.
 */
function dbPrelude(): string {
	return [
		'$CONFIG=[];require "config/config.php";$c=$CONFIG;',
		'$t=$c["dbtype"]??"sqlite3";',
		// `dbhost` IS NOT A HOSTNAME. Nextcloud stores host, host:port, or
		// :/path/to/socket in that one key, and CI's config holds
		// "127.0.0.1:5432" — pasted straight into `host=`, PDO reads the whole
		// string as a name and fails with
		//   SQLSTATE[08006] could not translate host name "127.0.0.1:5432"
		// which every caller then saw as an empty result, i.e. "zero rows".
		'$hraw=(string)($c["dbhost"]??"localhost");$hp="";$hs="";',
		'if(str_starts_with($hraw,":")){$hs=substr($hraw,1);$hraw="localhost";}',
		'elseif(str_contains($hraw,":")){[$hraw,$tail]=explode(":",$hraw,2);if(ctype_digit($tail)){$hp=$tail;}else{$hs=$tail;}}',
		'if($t==="sqlite3"){$dsn="sqlite:".($c["datadirectory"]??"data")."/".($c["dbname"]??"owncloud").".db";$u=null;$w=null;}',
		'elseif($t==="pgsql"){$dsn="pgsql:host=".$hraw.($hp!==""?";port=".$hp:"").";dbname=".$c["dbname"];$u=$c["dbuser"];$w=$c["dbpassword"];}',
		'else{$dsn="mysql:".($hs!==""?"unix_socket=".$hs:"host=".$hraw.($hp!==""?";port=".$hp:"")).";dbname=".$c["dbname"];$u=$c["dbuser"];$w=$c["dbpassword"];}',
		"$p=new PDO($dsn,$u,$w,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);",
	].join("");
}

/**
 * Installs a version of the fixture app and returns the structured outcome.
 *
 * Installs are driven through `occ versioniq:install` rather than the HTTP
 * API. Both call the same `InstallerService::installAppVersion`, so the forge
 * integration under test is identical — but the install swaps app files and
 * calls `opcache_reset()`, which under the test image's mod_php poisons the
 * shared web opcache and 503s the instance. `occ` runs with opcache disabled
 * (`opcache.enable_cli=Off`) and is the reproducible-provisioning path these
 * commands exist for, so it exercises the engine without the harness artifact.
 * The single HTTP-path install is covered separately in version-management.
 */
export async function installFixture(
	page: Page,
	version: string,
	opts: { allowDowngrade?: boolean; acceptNewSha?: boolean } = {},
): Promise<{ status: number; body: any }> {
	void page;
	const args = [
		"php",
		"occ",
		"versioniq:install",
		FIXTURE_APP,
		version,
		`--source=${FIXTURE_SOURCE}`,
		"--json",
	];
	if (opts.allowDowngrade) args.push("--allow-downgrade");
	if (opts.acceptNewSha) args.push("--accept-new-sha");

	// A non-zero exit (guard refused, integrity failure, …) still emits the
	// structured outcome on stdout — surface it with the exit code.
	const { code, stdout } = await execInInstance(args);
	const body = parseLastJson(stdout);

	// A SUCCESSFUL install that did not land is the failure mode this helper
	// has to catch, because every caller downstream reads the result as fact.
	//
	// `resetFixtureApp` has always known about it — "rapid sequential installs
	// each toggle maintenance mode, and an occasional overlap can make one
	// attempt a no-op" — and retries once with `maintenance:mode --off`. Every
	// OTHER caller got no such protection, and a no-op there is silent: exit 0,
	// a structured payload, and the app still on its previous version.
	//
	// It does not surface where it happens. It surfaces as whatever the test
	// asserted next, phrased as if that were the defect. jobs.spec.ts:169 is
	// the worked example: it installs 1.0.1, pins at 1.0.0, and expects the
	// reconcile job to record drift. When the install no-ops the app stays at
	// 1.0.0, installed == pinned, PinReconcileJob correctly records NO drift,
	// and the run reports `Error: drift recorded on the pin` — a true statement
	// about the job and a completely false lead about the cause. That test
	// failed on both attempts in CI on 2026-08-27; six more install-backed
	// tests were flaky in the same run.
	//
	// `InstallerService::installAppVersion` wraps the install in
	// `maintenance = true` and clears it in a `finally`, but only if IT set the
	// flag. A run that dies before that `finally` leaves the instance in
	// maintenance mode, and the next install then sees the flag already set,
	// declines to own it, proceeds anyway and leaves it on. Nothing reports it.
	//
	// So: only when the install CLAIMS TO HAVE INSTALLED. `installStatus` is the
	// marker the specs themselves assert on (`not.toBe('installed')` for the
	// refused cases), and gating on it rather than on the exit code alone
	// matters — `install-effects.spec.ts` calls this for an appId-mismatch
	// archive and asserts nothing at all about the outcome. Were that path ever
	// to exit 0 while reporting a failure, keying off `code` would turn a
	// passing test into a thrown error here. A deliberate failure (tamper,
	// wrong id, a refused guard) is left exactly as it was.
	const landed = () =>
		body?.installedVersion === version || body?.updateType === "none";
	const claimsInstalled = code === 0 && body?.installStatus === "installed";

	if (claimsInstalled && !landed()) {
		// Clear the stuck flag before retrying — this is the state that makes
		// the second attempt a no-op too.
		await occ("maintenance:mode", "--off");
		const retry = await execInInstance(args);
		const retryBody = parseLastJson(retry.stdout);
		if (
			retry.code === 0 &&
			(retryBody?.installedVersion === version ||
				retryBody?.updateType === "none")
		) {
			return { status: retry.code, body: retryBody };
		}

		throw new Error(
			`installFixture(${version}): occ reported success but the app is at ` +
				`${retryBody?.installedVersion ?? body?.installedVersion ?? "an unknown version"} ` +
				"after a retry. The install is a no-op, NOT a failing assertion in whatever " +
				"runs next — check whether the instance was left in maintenance mode by an " +
				"earlier install that died before its finally block.",
		);
	}

	return { status: code, body };
}

/** Extracts the last JSON object printed by an occ command (ignores warnings). */
function parseLastJson(out: string): any {
	const match = out.match(/\{[\s\S]*\}\s*$/);
	if (!match) return {};
	try {
		return JSON.parse(match[0]);
	} catch {
		return {};
	}
}

/**
 * A `YYYY-MM-DD HH:MM:SS` timestamp offset from now.
 *
 * The specs used SQLite's `datetime('now','-1 day')` and
 * `strftime('%Y-%m-%d %H:%M:%S','now')` inline. Those are not SQL — they are
 * SQLite builtins, and on the pgsql instance CI runs they are simply unknown
 * functions, so every statement carrying one fails. Computing the literal here
 * keeps the statements portable across sqlite, pgsql and mysql alike.
 *
 * @param days Offset in days; negative for the past.
 */
export function tsOffset(days = 0): string {
	return new Date(Date.now() + days * 86_400_000)
		.toISOString()
		.slice(0, 19)
		.replace("T", " ");
}

/**
 * What the discover API actually said, as an assertion message.
 *
 * The discover endpoint returns `{ results, providers, errors }`, and the specs
 * read only `results`. So when a provider fails — the App Store unreachable, a
 * forge refusing — the test reports `element(s) not found` or
 * `expect(0).toBeGreaterThan(0)`, which describes the SYMPTOM and hides the one
 * field that names the cause. The API is already telling us; nothing was
 * listening.
 *
 * Passed as the assertion's message so a failure carries the provider errors
 * beside it. It runs the same query the UI runs, so a search that works here
 * and not in the browser is itself the finding.
 *
 * @param page The Playwright page (used for its request context).
 * @param query The search term.
 */
export async function discoverDiagnostics(
	page: Page,
	query: string,
): Promise<string> {
	try {
		const res = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/discover?q=${encodeURIComponent(query)}&format=json`,
			{ headers: { "OCS-APIRequest": "true" } },
		);
		const data = (await res.json())?.ocs?.data ?? {};
		const errors = data.errors ?? [];
		const providers = (data.providers ?? []).map(
			(p: { id: string; enabled: boolean }) =>
				`${p.id}=${p.enabled ? "on" : "off"}`,
		);
		return (
			`discover(${query}) -> ${(data.results ?? []).length} result(s); ` +
			`providers: ${providers.join(", ") || "none"}; ` +
			`errors: ${errors.length > 0 ? JSON.stringify(errors) : "none"}`
		);
	} catch (err) {
		return `discover(${query}) -> the diagnostic request itself failed: ${String(err)}`;
	}
}

/** Runs an occ command against the instance, returning stdout. */
export async function occ(...args: string[]): Promise<string> {
	const { stdout } = await execInInstance(["php", "occ", ...args]);
	return stdout;
}

/** Runs a query against the instance's own database, returning stdout rows. */
export async function sql(query: string): Promise<string> {
	const { code, stdout, stderr } = await execInInstance([
		"php",
		"-r",
		`${dbPrelude()}$s=$p->query(${JSON.stringify(query)});foreach($s->fetchAll(PDO::FETCH_NUM) as $r){echo implode("\\t",array_map(fn($v)=>$v??"",$r)),"\\n";}`,
	]);
	reportDbFailure("sql", query, code, stderr);
	return stdout.trim();
}

/** Runs a mutating SQL statement against the instance's own database. */
export async function sqlExec(stmt: string): Promise<void> {
	const { code, stderr } = await execInInstance([
		"php",
		"-r",
		`${dbPrelude()}$p->exec(${JSON.stringify(stmt)});`,
	]);
	reportDbFailure("sqlExec", stmt, code, stderr);
}

/**
 * Surfaces a failed database call instead of letting it become an empty string.
 *
 * ⚠️ AN EMPTY RESULT AND A FAILED QUERY LOOK IDENTICAL TO THE CALLER, and the
 * callers all read the empty string as "zero rows". Measured 2026-08-19: a
 * dozen specs failed with `expect(received).toBeGreaterThan(0) / Received: 0`
 * — a message about the app's data, produced by a query that never ran.
 *
 * This deliberately does NOT throw. Several specs legitimately expect zero rows
 * (a swept PAT, a pruned audit row), so turning every failure into an exception
 * would replace one wrong answer with another. It writes the real cause to the
 * Playwright output, where the next reader can see it next to the assertion it
 * explains.
 *
 * @param helper Which helper failed.
 * @param statement The SQL that was attempted.
 * @param code The exit code from the instance.
 * @param stderr Whatever PHP said about it.
 */
function reportDbFailure(
	helper: string,
	statement: string,
	code: number,
	stderr: string,
): void {
	if (code === 0) {
		return;
	}

	console.error(
		`[e2e] ${helper}() exited ${code} — the result below is NOT "zero rows", it is a failed query.\n` +
			`      statement: ${statement}\n` +
			`      stderr:    ${stderr.trim() || "(none)"}`,
	);
}

/**
 * Force-executes an app background job by class-name substring, once.
 *
 * ⚠️ THROWS when no matching row exists, and it must. This previously read
 * `if (id) { … }`, so a missing `oc_jobs` row made the whole helper a silent
 * no-op: the job never ran, the test carried on, and the assertion that
 * followed failed with whatever the job was supposed to have produced —
 * "drift recorded on the pin" rather than "PinReconcileJob was never
 * executed". The two need different fixes, so they must not wear the same
 * words. A test asserting an ABSENCE fails even worse: it passes, because a
 * job that never ran records nothing.
 *
 * The row can genuinely go missing. This app has moved its job classes twice
 * — `lib/Cron` into `lib/BackgroundJob` (#231) and the app_versions ->
 * versioniq rename (#187) — and each move orphans the rows registered under
 * the old class string. RemoveRetiredCronJobs cleans up the first; nothing
 * cleans an `OCA\AppVersions\…` leftover.
 *
 * The match is also asserted to be UNIQUE. `LIKE '%…%' LIMIT 1` with no
 * ORDER BY picks an arbitrary row, so an orphan left beside the live job
 * could shadow it and be executed instead — silently, since executing a job
 * whose class no longer exists does nothing observable.
 */
export async function runJob(classSubstring: string): Promise<void> {
	// ⚠️ ANCHORED ON THIS APP'S OWN NAMESPACE, not just the class name.
	//
	// `LIKE '%PinReconcileJob%'` also matches the rows this app left behind
	// when it was renamed. Reproduced on a live instance 2026-08-27, oc_jobs
	// held BOTH:
	//
	//   OCA\Versioniq\BackgroundJob\PinReconcileJob   <- live, never run
	//   OCA\AppVersions\Cron\PinReconcileJob          <- orphan, ran daily
	//
	// and executing the orphan is a SILENT no-op that occ reports as success:
	// it prints a fresh "Last executed" timestamp and changes nothing, because
	// the class behind the row no longer exists. The drift test then failed
	// saying drift was not recorded — blaming PinDriftHandler, which is
	// correct and demonstrably records drift when the LIVE row is executed.
	//
	// Anchoring on `OCA\Versioniq\` cut the match from 2 rows to 1 on that
	// instance. `%\\%` still allows either sub-namespace (BackgroundJob today,
	// Cron before #231), so a future move within the app keeps working.
	const rows = (
		await sql(
			`SELECT id, class FROM oc_jobs WHERE class LIKE 'OCA\\\\Versioniq\\\\%${classSubstring}%'`,
		)
	)
		.split("\n")
		.map((line) => line.trim())
		.filter((line) => line.length > 0);

	if (rows.length === 0) {
		throw new Error(
			`runJob(${classSubstring}): no oc_jobs row matches OCA\\Versioniq\\…${classSubstring}. ` +
				"The job was NOT executed. This is a broken fixture, not a failing assertion — " +
				"check that the app is enabled and that the class is still the one registered in " +
				"appinfo/info.xml. (A row under a PRE-RENAME namespace is deliberately not " +
				"matched: executing it would do nothing and report success.)",
		);
	}
	if (rows.length > 1) {
		throw new Error(
			`runJob(${classSubstring}): ${rows.length} oc_jobs rows match, so which one runs ` +
				`is arbitrary. Rows: ${rows.join(" | ")}. An orphaned row from a class rename ` +
				"must be removed before this can mean anything.",
		);
	}

	const id = rows[0].split("\t")[0].trim();
	await occ("background-job:execute", id, "--force-execute");
}

/** The fixture app's clean source binding, with no recorded digests. */
const CLEAN_FIXTURE_BINDING = JSON.stringify({
	kind: "github-release",
	forge: "codeberg",
	owner: "fixtureowner",
	repo: "fixtureapp",
	assetPattern: "*.tar.gz",
});

/** Resets the fixture app to its 1.0.0 baseline via the real install path. */
export async function resetFixtureApp(page: Page): Promise<void> {
	// Restore the fixture forge's default release set + clear asset overrides
	// first, so the baseline install below can always fetch a genuine 1.0.0.
	await resetFixture(page);
	// Clear any pin and — crucially — the recorded SHA-256 map, which lives in
	// the binding config and would otherwise leak across tests (a test that
	// records a rewritten digest would make the next test's tamper "match").
	await page.request
		.delete(
			`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`,
			{
				headers: { "OCS-APIRequest": "true" },
			},
		)
		.catch(() => undefined);
	await occ(
		"config:app:set",
		"versioniq",
		`source.${FIXTURE_APP}`,
		"--value",
		CLEAN_FIXTURE_BINDING,
	);
	// Clear the artifact cache too: a genuine copy cached by a prior test would
	// otherwise be served as a download fallback and mask a tampered forge.
	await page.request
		.delete("/ocs/v2.php/apps/versioniq/api/cache?format=json", {
			headers: { "OCS-APIRequest": "true" },
		})
		.catch(() => undefined);

	// Install the baseline, retrying once: rapid sequential installs each toggle
	// maintenance mode, and an occasional overlap can make one attempt a no-op.
	for (let attempt = 0; attempt < 2; attempt++) {
		const { body } = await installFixture(page, "1.0.0", {
			allowDowngrade: true,
		});
		await occ("maintenance:mode", "--off");
		if (body.installedVersion === "1.0.0" || body.updateType === "none")
			break;
	}
}
