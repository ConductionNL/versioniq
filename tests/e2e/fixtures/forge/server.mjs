// Fixture forge — a Forgejo/Gitea-shaped HTTP double for Versioniq e2e tests.
//
// It answers the three endpoints ForgeReleaseSource / PatValidator hit
// (`/api/v1/repos/{owner}/{repo}/releases`, `.../security-advisories`,
// `/api/v1/user`), serves prebuilt app tarballs from ./artifacts, and exposes a
// small `/control` surface so a spec can rewrite a release, delete an asset,
// force a 404/429, or set an advisory feed — the states that are impossible to
// reach against a real forge. It also records webhook deliveries posted to
// `/hooks/{name}`, which a spec reads back through `/control/webhooks`.
//
// The app is pointed here by setting `forge.forgejo.api_base` /
// `forge.forgejo.web_base` app config to this server's URL (see docs/e2e.md).
import { existsSync, readFileSync } from "node:fs";
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { readdir, readFile } from "node:fs/promises";
import { createServer } from "node:http";
import { createServer as createTlsServer } from "node:https";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ARTIFACTS = join(HERE, "artifacts");
const PORT = Number(process.env.PORT || 9099);
// The https listener for the OSV double: `advisory.osv_api_base` is https only.
const TLS_PORT = Number(process.env.TLS_PORT || 9443);
const TLS_DIR = join(ARTIFACTS, "tls");
// Advertised base the app downloads from — must match how the app reaches us.
const PUBLIC_BASE = process.env.PUBLIC_BASE || `http://forge-fixture:${PORT}`;

// The App Store catalogue this fixture serves in place of garm3.nextcloud.com.
//
// Every id here is an app the CI instance ACTUALLY HAS INSTALLED, which is the
// whole point: the specs assert that a discovery hit reports its
// installedVersion, and only the server can supply that. Inventing ids would
// make those assertions pass while proving nothing.
//
// `notes` carries several releases because versions.spec walks the list,
// selects an older release and asserts the downgrade guard refuses it.
const APPSTORE_CATALOG = [
	{
		id: "notes",
		name: { en: "Notes" },
		summary: { en: "Take notes, sync them with your devices" },
		website: "https://github.com/nextcloud/notes",
		releases: [
			{ version: "4.13.0", isNightly: false },
			{ version: "4.12.0", isNightly: false },
			{ version: "4.11.0", isNightly: false },
			{ version: "4.10.1", isNightly: false },
		],
	},
	{
		id: "calendar",
		name: { en: "Calendar" },
		summary: { en: "A calendar app for Nextcloud" },
		website: "https://github.com/nextcloud/calendar",
		// discovery.spec asserts the publisher and the featured mark; the mail
		// address must never reach the page.
		authors: [
			{
				name: "Fixture Calendar Team",
				mail: "calendar-team@example.test",
				homepage: "",
			},
		],
		isFeatured: true,
		releases: [
			{ version: "5.4.0", isNightly: false },
			{ version: "5.3.0", isNightly: false },
		],
	},
	{
		id: "notifications",
		name: { en: "Notifications" },
		summary: { en: "Central notifications for Nextcloud" },
		website: "https://github.com/nextcloud/notifications",
		releases: [
			{ version: "4.2.0", isNightly: false },
			{ version: "4.1.0", isNightly: false },
		],
	},
	{
		id: "dashboard",
		name: { en: "Dashboard" },
		summary: { en: "A dashboard for Nextcloud" },
		website: "https://github.com/nextcloud/dashboard",
		// Deliberately BELOW whatever Nextcloud bundles, on every server version
		// in the matrix. versions.spec asserts that safe mode hides every
		// candidate (all downgrades) and that switching it off reveals them, so
		// these must be unambiguously older than the installed build rather than
		// merely older than one particular release. 1.x is never a real
		// dashboard version, which is the point — no server ships it.
		releases: [
			{ version: "1.3.0", isNightly: false },
			{ version: "1.2.0", isNightly: false },
			{ version: "1.1.0", isNightly: false },
			{ version: "1.0.0", isNightly: false },
		],
	},
	{
		id: "files",
		name: { en: "Files" },
		summary: { en: "File management for Nextcloud" },
		website: "https://github.com/nextcloud/server",
		releases: [{ version: "2.4.0", isNightly: false }],
	},
];

// The default release set for fixtureowner/fixtureapp. `asset` is the artifact
// filename served for that tag; `sha` toggles whether a .sha256 sibling is
// advertised.
const DEFAULT_RELEASES = [
	{ tag: "v1.2.0", asset: "fixtureapp-1.2.0.tar.gz", sha: true },
	{ tag: "v1.1.0", asset: "fixtureapp-1.1.0.tar.gz", sha: true },
	{ tag: "v1.0.1", asset: "fixtureapp-1.0.1.tar.gz", sha: true },
	{ tag: "v1.0.0", asset: "fixtureapp-1.0.0.tar.gz", sha: true },
	{ tag: "v0.9.0", asset: "fixtureapp-0.9.0.tar.gz", sha: true },
];

// Mutable state, resettable via POST /control/reset.
let state = freshState();
function freshState() {
	return {
		// repoKey -> { status?:number, releases:[...], advisories:[...] }
		repos: {
			"fixtureowner/fixtureapp": {
				releases: structuredClone(DEFAULT_RELEASES),
				advisories: [],
			},
			// A second app on the same release line.
			"fixtureowner/fixtureapp2": {
				releases: DEFAULT_RELEASES.filter((r) => r.tag !== "v0.9.0").map(
					(r) => ({
						...r,
						asset: r.asset.replace("fixtureapp-", "fixtureapp2-"),
					}),
				),
				advisories: [],
			},
			// The same releases as a GitLab project in a subgroup.
			"fixturegroup/sub/fixtureapp": {
				releases: structuredClone(DEFAULT_RELEASES),
				advisories: [],
			},
		},
		// artifact filename -> override filename actually served (rewrite/tamper)
		assetOverride: {},
		// artifact filename -> status code to force (e.g. 404 for a deleted asset)
		assetStatus: {},
		// The NCSC-NL CSAF directory: forced status on changes.csv, documents.
		csaf: { status: null, documents: defaultCsaf() },
		// Webhook deliveries received on /hooks/{name}, oldest first.
		webhooks: [],
		// Versions /verdicts/{appId}/{version} answers 200 for; any other is 404.
		verdictsPassed: ["1.0.1"],
	};
}

function json(res, code, body, headers = {}) {
	const payload = Buffer.from(JSON.stringify(body));
	res.writeHead(code, {
		"content-type": "application/json",
		"content-length": payload.length,
		...headers,
	});
	res.end(payload);
}

async function readBody(req) {
	const chunks = [];
	for await (const c of req) chunks.push(c);
	if (!chunks.length) return {};
	try {
		return JSON.parse(Buffer.concat(chunks).toString("utf8"));
	} catch {
		return {};
	}
}

// Build the Forgejo release JSON for a repo's configured releases.
function releaseJson(repo) {
	return repo.releases.map((r) => {
		const assets = [
			{
				name: r.asset,
				browser_download_url: `${PUBLIC_BASE}/dl/${r.asset}`,
			},
		];
		if (r.sha) {
			assets.push({
				name: `${r.asset}.sha256`,
				browser_download_url: `${PUBLIC_BASE}/dl/${r.asset}.sha256`,
			});
		}
		return {
			tag_name: r.tag,
			name: r.name ?? r.tag,
			// Release notes — mapped to the version's changelog by the app.
			body:
				r.body ?? `## ${r.tag}\n\n- Fixture release notes for ${r.tag}`,
			assets,
			// Publisher marks a spec can set through /control/repo.
			...(r.prerelease !== undefined ? { prerelease: r.prerelease } : {}),
			...(r.published_at !== undefined
				? { published_at: r.published_at }
				: {}),
		};
	});
}

// --- NCSC-NL CSAF directory (advisories-ncsc-feed) -----------------------
// A small CSAF provider directory under /csaf/v2/: a `changes.csv` (newest
// first) and the documents it lists. NcscAdvisoryFeed reads it once
// `advisory.ncsc_feed_base` points here. The defaults are two documents about
// Nextcloud Server: one whose range covers any server the CI runs (so the
// server is affected) and one with `vers:unknown/*` (so NCSC-NL "does not
// state which versions"). Times are relative to now so the feed's 365-day
// first read always includes them. POST /control/csaf replaces the documents
// or forces a status on `changes.csv`.
function csafDocument(id, title, range, cve, severity, ratings) {
	const vulnerability = {
		cve,
		product_status: { known_affected: ["CSAFPID-0001"] },
	};
	if (severity)
		vulnerability.scores = [
			{ products: ["CSAFPID-0001"], cvss_v3: { baseSeverity: severity } },
		];
	return {
		document: {
			category: "csaf_security_advisory",
			csaf_version: "2.0",
			title,
			tracking: {
				id,
				initial_release_date: new Date(Date.now() - 86_400_000).toISOString(),
				version: "1.00",
			},
			notes: [
				{ category: "other", title: "Kans", text: ratings[0] },
				{ category: "other", title: "Schade", text: ratings[1] },
			],
		},
		product_tree: {
			branches: [
				{
					category: "vendor",
					name: "Nextcloud",
					branches: [
						{
							category: "product_name",
							name: "Nextcloud Server",
							branches: [
								{
									category: "product_version_range",
									name: range,
									product: {
										product_id: "CSAFPID-0001",
										name: `Nextcloud Server ${range}`,
									},
								},
							],
						},
					],
				},
			],
		},
		vulnerabilities: [vulnerability],
	};
}

function defaultCsaf() {
	return [
		{
			path: "2099/ncsc-2099-0001.json",
			doc: csafDocument(
				"NCSC-2099-0001",
				"Kwetsbaarheid verholpen in Nextcloud Server",
				"vers:generic/>=1.0.0|<999.0.0",
				"CVE-2099-0001",
				"HIGH",
				["medium", "high"],
			),
		},
		{
			path: "2099/ncsc-2099-0002.json",
			doc: csafDocument(
				"NCSC-2099-0002",
				"Kwetsbaarheid in Nextcloud Server zonder versies",
				"vers:unknown/*",
				"CVE-2099-0002",
				null,
				["low", "medium"],
			),
		},
	];
}

function csafChanges(res) {
	if (state.csaf.status) {
		res.writeHead(state.csaf.status);
		return res.end("forced");
	}
	// Newest first, as NCSC-NL publishes it; every line a minute apart.
	const now = Date.now();
	const body = state.csaf.documents
		.map((d, i) => {
			const changed = new Date(
				now - 3_600_000 + i * 60_000,
			).toISOString();
			return `"${d.path}","${d.changed ?? changed}"`;
		})
		.reverse()
		.join("\n");
	res.writeHead(200, {
		"content-type": "text/csv",
		"content-length": Buffer.byteLength(body),
	});
	return res.end(body);
}

function csafApi(res, path) {
	const m = path.match(/^\/csaf\/v2\/(.+)$/);
	if (!m) return false;
	if (m[1] === "changes.csv") {
		csafChanges(res);
		return true;
	}
	const found = state.csaf.documents.find((d) => d.path === m[1]);
	if (found) json(res, 200, found.doc);
	else json(res, 404, { message: "Not Found" });
	return true;
}

async function controlCsaf(req, res) {
	// { status?, documents?: [{ path, changed?, doc }] }
	const b = await readBody(req);
	state.csaf = {
		status: b.status ?? null,
		documents: b.documents ?? state.csaf.documents,
	};
	return json(res, 200, { ok: true, documents: state.csaf.documents.length });
}

// --- Control plane (test-only) -------------------------------------------
async function controlRepo(req, res) {
	// { repo, status?, releases?, advisories? }
	const b = await readBody(req);
	const key = b.repo || "fixtureowner/fixtureapp";
	state.repos[key] = {
		status: b.status,
		requireAuth: b.requireAuth ?? false,
		releases: b.releases ?? state.repos[key]?.releases ?? [],
		advisories: b.advisories ?? state.repos[key]?.advisories ?? [],
	};
	return json(res, 200, { ok: true, repo: key });
}

async function controlAsset(req, res) {
	// { asset, status?, serveInstead? }  — force a status or swap bytes
	const b = await readBody(req);
	if (b.status !== null && b.status !== undefined)
		state.assetStatus[b.asset] = b.status;
	if (b.serveInstead !== null && b.serveInstead !== undefined)
		state.assetOverride[b.asset] = b.serveInstead;
	return json(res, 200, { ok: true });
}

// --- Webhook receiver (releases-new-release-alerts) ----------------------
// A webhook target stored with an http address on this fixture posts its
// deliveries to `/hooks/{name}`. Each is recorded with its headers and body and
// answered 200; POST /control/webhooks `{ name? }` lists them (for one hook
// name when given), and `{ clear: true }` forgets them.
async function receiveWebhook(req, res, name) {
	const chunks = [];
	for await (const c of req) chunks.push(c);
	const raw = Buffer.concat(chunks).toString("utf8");
	let body;
	try {
		body = JSON.parse(raw);
	} catch {
		body = null;
	}
	state.webhooks.push({
		name,
		headers: req.headers,
		raw,
		body,
		receivedAt: new Date().toISOString(),
	});
	return json(res, 200, { ok: true });
}

async function controlWebhooks(req, res) {
	// { name?, clear? }
	const b = await readBody(req);
	if (b.clear === true) state.webhooks = [];
	const deliveries = b.name
		? state.webhooks.filter((d) => d.name === b.name)
		: state.webhooks;
	return json(res, 200, { ok: true, deliveries });
}

// --- Update-check verdicts (safety-update-checks) ------------------------
// A verdict check stored with the address `{base}/verdicts/{appId}/{version}`
// asks this route whether a version passed its tests. It answers 200 for a
// version in `state.verdictsPassed` and 404 for any other, the way a CI system
// answers for a build it has no green run of. POST /control/verdicts
// `{ passed: [...] }` replaces the passing versions.
function verdictApi(res, path) {
	const m = path.match(/^\/verdicts\/([A-Za-z0-9_-]+)\/([^/]+)$/);
	if (!m) return false;
	const version = decodeURIComponent(m[2]);
	if (state.verdictsPassed.includes(version)) {
		json(res, 200, { app: m[1], version, verdict: "passed" });
	} else {
		json(res, 404, { app: m[1], version, verdict: "unknown" });
	}
	return true;
}

async function controlVerdicts(req, res) {
	const b = await readBody(req);
	if (Array.isArray(b.passed)) state.verdictsPassed = b.passed.map(String);
	return json(res, 200, { ok: true, passed: state.verdictsPassed });
}

const CONTROL_ROUTES = {
	"/control/reset": (_req, res) => {
		state = freshState();
		return json(res, 200, { ok: true });
	},
	"/control/repo": controlRepo,
	"/control/asset": controlAsset,
	"/control/csaf": controlCsaf,
	"/control/webhooks": controlWebhooks,
	"/control/verdicts": controlVerdicts,
};

// --- Forge API (Forgejo `/api/v1/...` and GitHub `/...` shapes) -----------
// A token containing "revoked" is invalid, so PAT validation can exercise the
// rejection path. Scopes and expiry are derived from the token so tests are
// deterministic: "scope-repo" -> repo only; "scope-admin" -> over-broad;
// "expires" -> a near expiry header.
function forgeUser(res, auth) {
	if (auth.includes("revoked")) {
		return json(res, 401, { message: "Unauthorized" });
	}
	const headers = {};
	if (auth.includes("scope-admin"))
		headers["x-oauth-scopes"] = "repo, admin:org, write:packages";
	else if (auth.includes("scope-repo")) headers["x-oauth-scopes"] = "repo";
	if (auth.includes("expires"))
		headers["github-authentication-token-expiration"] =
			"2026-08-15 12:00:00 UTC";
	return json(res, 200, { login: "fixture-bot", id: 1 }, headers);
}

// The error a repo endpoint answers with instead of its body, or null.
function repoError(repo, { checkAuth, auth }) {
	if (!repo) return [404, { message: "Not Found" }];
	if (checkAuth && repo.requireAuth && !auth)
		return [404, { message: "Not Found" }];
	if (repo.status) return [repo.status, { message: `forced ${repo.status}` }];
	return null;
}

const REPO_ROUTES = [
	{
		pattern: /^\/repos\/([^/]+)\/([^/]+)\/releases$/,
		checkAuth: true,
		body: releaseJson,
	},
	{
		pattern: /^\/repos\/([^/]+)\/([^/]+)\/security-advisories$/,
		checkAuth: false,
		body: (repo) => repo.advisories ?? [],
	},
];

// GitHub base is `https://api.github.com` (no `/api/v1`); a Forgejo host is
// `https://<host>/api/v1`. Accept an optional `/api/v1` prefix so one fixture
// serves both when the github and forgejo base URLs point here. Returns false
// when the path is not a forge endpoint.
function forgeApi(req, res, path) {
	const api = path.replace(/^\/api\/v1/, "");
	const auth = req.headers.authorization ?? "";
	if (api === "/user") {
		forgeUser(res, auth);
		return true;
	}
	for (const route of REPO_ROUTES) {
		const m = api.match(route.pattern);
		if (!m) continue;
		const repo = state.repos[`${m[1]}/${m[2]}`];
		const error = repoError(repo, { checkAuth: route.checkAuth, auth });
		if (error) json(res, ...error);
		else json(res, 200, route.body(repo));
		return true;
	}
	return false;
}

// GitLab `/api/v4/projects/{url-encoded path}/releases`: `description` for the
// notes, `released_at` for the date, and `assets.links[]` for the files.
function gitlabApi(res, path) {
	const m = path.match(/^\/api\/v4\/projects\/([^/]+)\/releases$/);
	if (!m) return false;
	const repo = state.repos[decodeURIComponent(m[1])];
	const error = repoError(repo, { checkAuth: false, auth: "" });
	if (error) {
		json(res, ...error);
		return true;
	}
	json(
		res,
		200,
		releaseJson(repo).map((r) => ({
			tag_name: r.tag_name,
			name: r.name,
			description: r.body,
			released_at: r.published_at ?? null,
			upcoming_release: false,
			assets: {
				links: r.assets.map((a) => ({
					name: a.name,
					url: a.browser_download_url,
					direct_asset_url: a.browser_download_url,
				})),
			},
		})),
	);
	return true;
}

// --- OSV double (advisories-bundled-libraries) -----------------------------
//
// Answers the two OSV endpoints OsvClient calls: `/v1/querybatch` (one result
// per query, in order) and `/v1/vulns/{id}`. It knows one advisory, for the
// `vendor/library` 2.4.3 that build-artifacts.sh bundles in the fixture app,
// fixed in 2.4.5; every other library — including whatever the server's own
// apps bundle — has none, so the finding the spec asserts is the only one.
const OSV_RECORDS = {
	"GHSA-fx00-0000-0001": {
		id: "GHSA-fx00-0000-0001",
		aliases: ["CVE-2099-0001"],
		summary: "Fixture advisory for the bundled-library e2e spec",
		database_specific: { severity: "HIGH" },
		affected: [
			{
				package: { ecosystem: "Packagist", name: "vendor/library" },
				ranges: [
					{
						type: "ECOSYSTEM",
						events: [{ introduced: "0" }, { fixed: "2.4.5" }],
					},
				],
			},
		],
	},
};

/** The record ids that affect one library version. */
function osvIdsFor(name, version) {
	return Object.values(OSV_RECORDS)
		.filter((record) =>
			record.affected.some(
				(affected) =>
					affected.package.ecosystem === "Packagist" &&
					affected.package.name === String(name).toLowerCase() &&
					affected.ranges.some((range) =>
						range.events.some(
							(event) =>
								event.fixed !== undefined &&
								compareVersions(version, event.fixed) < 0,
						),
					),
			),
		)
		.map((record) => record.id);
}

/** Numeric dotted-version comparison; enough for the fixture's versions. */
function compareVersions(a, b) {
	const left = String(a).replace(/^v/, "").split(".").map(Number);
	const right = String(b).replace(/^v/, "").split(".").map(Number);
	for (let i = 0; i < Math.max(left.length, right.length); i++) {
		const diff = (left[i] || 0) - (right[i] || 0);
		if (diff !== 0) return diff;
	}
	return 0;
}

async function osvApi(req, res, path) {
	if (path === "/v1/querybatch" && req.method === "POST") {
		const body = await readBody(req);
		const queries = Array.isArray(body.queries) ? body.queries : [];
		const results = queries.map((query) => {
			const ids = osvIdsFor(query?.package?.name ?? "", query?.version ?? "");
			return ids.length
				? { vulns: ids.map((id) => ({ id, modified: "2026-01-01T00:00:00Z" })) }
				: {};
		});
		json(res, 200, { results });
		return true;
	}
	const vuln = path.match(/^\/v1\/vulns\/([^/]+)$/);
	if (vuln) {
		const record = OSV_RECORDS[decodeURIComponent(vuln[1])];
		if (record) json(res, 200, record);
		else json(res, 404, { code: 5, message: "Bug not found." });
		return true;
	}
	return false;
}

// --- Artifact download -----------------------------------------------------
async function download(res, requested) {
	const forced = state.assetStatus[requested];
	if (forced) {
		res.writeHead(forced);
		return res.end(`forced ${forced}`);
	}
	const served = state.assetOverride[requested] ?? requested;
	try {
		const bytes = await readFile(join(ARTIFACTS, served));
		res.writeHead(200, {
			"content-type": "application/octet-stream",
			"content-length": bytes.length,
		});
		return res.end(bytes);
	} catch {
		res.writeHead(404);
		return res.end("artifact not found");
	}
}

// ── App Store double ─────────────────────────────────────────────────────
//
// The real catalogue is ~12.4 MB from garm3.nextcloud.com, and reaching it
// from a CI runner is what left the discovery and version-listing specs timing
// out at 20s while every other endpoint answered in 60ms. A forge double
// already removes that dependency for forge sources; this does the same for
// the App Store, so the suite stops asserting on somebody else's uptime.
//
// The entries are REAL app ids that the CI instance actually has installed —
// files, dashboard, notifications, notes — because the specs assert that a hit
// reports its installedVersion, which only the server can supply. Inventing
// ids would make every such assertion vacuous.
function appStore(res, url) {
	const filter = url.searchParams.get("filter");
	const page = Number(url.searchParams.get("page") || 1);
	// AppStoreSource pages until told otherwise; everything fits on one.
	if (page > 1) return json(res, 200, { data: [], pages: { next: false } });
	const catalog = filter
		? APPSTORE_CATALOG.filter((a) => a.id === filter)
		: APPSTORE_CATALOG;
	return json(res, 200, { data: catalog, pages: { next: false } });
}

async function route(req, res) {
	const url = new URL(req.url, `http://localhost:${PORT}`);
	const path = url.pathname;

	const control = CONTROL_ROUTES[path];
	if (control && req.method === "POST") return control(req, res);

	if (csafApi(res, path)) return;
	if (verdictApi(res, path)) return;
	if (await osvApi(req, res, path)) return;
	if (gitlabApi(res, path)) return;
	if (forgeApi(req, res, path)) return;

	const hook = path.match(/^\/hooks\/([A-Za-z0-9_-]+)$/);
	if (hook && req.method === "POST") return receiveWebhook(req, res, hook[1]);

	const dl = path.match(/^\/dl\/(.+)$/);
	if (dl) return download(res, dl[1]);

	if (path === "/appstore/api/v1/apps.json" || path.endsWith("/apps.json"))
		return appStore(res, url);

	if (path === "/health") return json(res, 200, { ok: true });

	res.writeHead(404);
	res.end("not found");
}

async function handle(req, res) {
	try {
		await route(req, res);
	} catch (err) {
		res.writeHead(500);
		res.end(String(err));
	}
}

const server = createServer(handle);

// The same routes over TLS, when build-artifacts.sh generated a certificate.
if (existsSync(join(TLS_DIR, "fixture.crt"))) {
	createTlsServer(
		{
			cert: readFileSync(join(TLS_DIR, "fixture.crt")),
			key: readFileSync(join(TLS_DIR, "fixture.key")),
		},
		handle,
	).listen(TLS_PORT, () => {
		console.log(`forge-fixture https listening on :${TLS_PORT}`);
	});
}

server.listen(PORT, async () => {
	const names = await readdir(ARTIFACTS).catch(() => []);

	console.log(
		`forge-fixture listening on :${PORT}, ${names.length} artifacts, public base ${PUBLIC_BASE}`,
	);
});
