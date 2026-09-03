import { readdir, readFile } from "node:fs/promises";
// Fixture forge — a Forgejo/Gitea-shaped HTTP double for Versioniq e2e tests.
//
// It answers the three endpoints ForgeReleaseSource / PatValidator hit
// (`/api/v1/repos/{owner}/{repo}/releases`, `.../security-advisories`,
// `/api/v1/user`), serves prebuilt app tarballs from ./artifacts, and exposes a
// small `/control` surface so a spec can rewrite a release, delete an asset,
// force a 404/429, or set an advisory feed — the states that are impossible to
// reach against a real forge.
//
// The app is pointed here by setting `forge.codeberg.api_base` /
// `forge.codeberg.web_base` app config to this server's URL (see docs/e2e.md).
import { createServer } from "node:http";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const ARTIFACTS = join(HERE, "artifacts");
const PORT = Number(process.env.PORT || 9099);
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
		},
		// artifact filename -> override filename actually served (rewrite/tamper)
		assetOverride: {},
		// artifact filename -> status code to force (e.g. 404 for a deleted asset)
		assetStatus: {},
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
			name: r.tag,
			// Release notes — mapped to the version's changelog by the app.
			body:
				r.body ?? `## ${r.tag}\n\n- Fixture release notes for ${r.tag}`,
			assets,
		};
	});
}

const server = createServer(async (req, res) => {
	try {
		const url = new URL(req.url, `http://localhost:${PORT}`);
		const path = url.pathname;

		// --- Control plane (test-only) -------------------------------------
		if (path === "/control/reset" && req.method === "POST") {
			state = freshState();
			return json(res, 200, { ok: true });
		}
		if (path === "/control/repo" && req.method === "POST") {
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
		if (path === "/control/asset" && req.method === "POST") {
			// { asset, status?, serveInstead? }  — force a status or swap bytes
			const b = await readBody(req);
			if (b.status !== null && b.status !== undefined)
				state.assetStatus[b.asset] = b.status;
			if (b.serveInstead !== null && b.serveInstead !== undefined)
				state.assetOverride[b.asset] = b.serveInstead;
			return json(res, 200, { ok: true });
		}

		// --- Forge API (Forgejo `/api/v1/...` and GitHub `/...` shapes) -----
		// GitHub base is `https://api.github.com` (no `/api/v1`); Codeberg is
		// `https://codeberg.org/api/v1`. Accept an optional `/api/v1` prefix so
		// one fixture serves both when github/codeberg base URLs point here.
		const api = path.replace(/^\/api\/v1/, "");
		const auth = req.headers.authorization ?? "";

		if (api === "/user") {
			// A token containing "revoked" is invalid, so PAT validation can
			// exercise the rejection path. Scopes and expiry are derived from the
			// token so tests are deterministic: "scope-repo" -> repo only;
			// "scope-admin" -> over-broad; "expires" -> a near expiry header.
			if (auth.includes("revoked")) {
				return json(res, 401, { message: "Unauthorized" });
			}
			const headers = {};
			if (auth.includes("scope-admin"))
				headers["x-oauth-scopes"] = "repo, admin:org, write:packages";
			else if (auth.includes("scope-repo"))
				headers["x-oauth-scopes"] = "repo";
			if (auth.includes("expires"))
				headers["github-authentication-token-expiration"] =
					"2026-08-15 12:00:00 UTC";
			return json(res, 200, { login: "fixture-bot", id: 1 }, headers);
		}

		let m = api.match(/^\/repos\/([^/]+)\/([^/]+)\/releases$/);
		if (m) {
			const repo = state.repos[`${m[1]}/${m[2]}`];
			if (!repo) return json(res, 404, { message: "Not Found" });
			if (repo.requireAuth && !auth)
				return json(res, 404, { message: "Not Found" });
			if (repo.status)
				return json(res, repo.status, {
					message: `forced ${repo.status}`,
				});
			return json(res, 200, releaseJson(repo));
		}

		m = api.match(/^\/repos\/([^/]+)\/([^/]+)\/security-advisories$/);
		if (m) {
			const repo = state.repos[`${m[1]}/${m[2]}`];
			if (!repo) return json(res, 404, { message: "Not Found" });
			if (repo.status)
				return json(res, repo.status, {
					message: `forced ${repo.status}`,
				});
			return json(res, 200, repo.advisories ?? []);
		}

		// --- Artifact download ---------------------------------------------
		m = path.match(/^\/dl\/(.+)$/);
		if (m) {
			const requested = m[1];
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

		// ── App Store double ─────────────────────────────────────────────────
		//
		// The real catalogue is ~12.4 MB from garm3.nextcloud.com, and reaching
		// it from a CI runner is what left the discovery and version-listing
		// specs timing out at 20s while every other endpoint answered in 60ms.
		// A forge double already removes that dependency for forge sources;
		// this does the same for the App Store, so the suite stops asserting on
		// somebody else's uptime.
		//
		// The entries are REAL app ids that the CI instance actually has
		// installed — files, dashboard, notifications, notes — because the
		// specs assert that a hit reports its installedVersion, which only the
		// server can supply. Inventing ids would make every such assertion
		// vacuous.
		if (
			path === "/appstore/api/v1/apps.json" ||
			path.endsWith("/apps.json")
		) {
			const filter = url.searchParams.get("filter");
			const page = Number(url.searchParams.get("page") || 1);
			// AppStoreSource pages until told otherwise; everything fits on one.
			if (page > 1)
				return json(res, 200, { data: [], pages: { next: false } });
			const catalog = filter
				? APPSTORE_CATALOG.filter((a) => a.id === filter)
				: APPSTORE_CATALOG;
			return json(res, 200, { data: catalog, pages: { next: false } });
		}

		if (path === "/health") return json(res, 200, { ok: true });

		res.writeHead(404);
		res.end("not found");
	} catch (err) {
		res.writeHead(500);
		res.end(String(err));
	}
});

server.listen(PORT, async () => {
	const names = await readdir(ARTIFACTS).catch(() => []);

	console.log(
		`forge-fixture listening on :${PORT}, ${names.length} artifacts, public base ${PUBLIC_BASE}`,
	);
});
