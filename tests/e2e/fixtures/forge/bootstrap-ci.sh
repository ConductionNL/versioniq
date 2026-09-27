#!/usr/bin/env bash
# Bootstraps the fixture forge for e2e IN CI, where there is no Docker.
#
# ── WHY A SECOND BOOTSTRAP ────────────────────────────────────────────────────
#
# `bootstrap.sh` assumes the developer layout: Nextcloud in a container named
# `av-e2e`, the fixture in a second container, the two joined by a Docker
# network, and the app pointed at `http://forge-fixture:9099`. None of that
# exists in the shared quality.yml job — there Nextcloud is the PHP built-in
# server running on the runner itself, and `occ` is a plain `php occ`.
#
# So the fixture never started in CI, `fixtureAvailable()` answered false, and
# 66 specs across ten files skipped with "forge fixture not running". Measured
# 2026-08-19 on the first run in which versioniq' E2E job executed at all:
#
#     7 failed · 3 flaky · 66 skipped · 22 passed
#
# 🔑 THOSE 66 ARE THE INTERESTING ONES. They cover the CLI commands, downgrade
# migration drift, the artifact cache, TOFU digest recording, install faults and
# rate-limited forges — i.e. most of what this app is for. A suite reporting 22
# passed while two thirds of it never ran reads as a pass, and a skip whose
# reason has stopped being true is worse than no skip because it looks
# considered.
#
# Everything the fixture needs is already parameterised (`PORT`, `PUBLIC_BASE`,
# `FORGE_FIXTURE_URL`), so CI needs no new fixture code — only a different way
# to start it and to point the app at it.
#
# Invoked from .github/workflows/code-quality.yml as `playwright-seed-command`,
# which runs with cwd at the Nextcloud server root and FAILS THE JOB on a
# non-zero exit. That is deliberate: if the fixture cannot start, the honest
# outcome is a red job, not 66 tests skipping for a reason that is no longer
# true.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
set -euo pipefail

NC_ROOT="$(pwd)"
APP_DIR="${NC_ROOT}/apps/versioniq"
FX="${APP_DIR}/tests/e2e/fixtures/forge"
PORT="${FORGE_FIXTURE_PORT:-9099}"
BASE="http://localhost:${PORT}"
LOG="${RUNNER_TEMP:-/tmp}/forge-fixture.log"

# ASSERT THE LAYOUT BEFORE ACTING. If the app is not where this expects, every
# path below silently addresses nothing and the script would still exit 0.
[ -d "${FX}" ] || { echo "::error::forge fixture not found at ${FX} (cwd=${NC_ROOT})"; exit 1; }
[ -f "${NC_ROOT}/occ" ] || { echo "::error::no occ at ${NC_ROOT}/occ — cwd is not a Nextcloud server root"; exit 1; }

echo "Building fixture artifacts…"
"${FX}/build-artifacts.sh" "${FX}/artifacts" > /dev/null

# PUBLIC_BASE is what the fixture advertises in its release payloads, i.e. the
# URL the APP will download from. In CI both the app and the fixture live on the
# runner, so that is localhost — the container hostname the Docker bootstrap
# uses would resolve to nothing here.
echo "Starting fixture forge on ${BASE}…"
PORT="${PORT}" PUBLIC_BASE="${BASE}" nohup node "${FX}/server.mjs" > "${LOG}" 2>&1 &
FX_PID=$!

ready=0
for _ in $(seq 1 30); do
	if curl -sf -m 2 "${BASE}/health" > /dev/null 2>&1; then
		ready=1
		break
	fi

	# A dead process will never become ready, so stop waiting for it and show
	# why — a bare 30-second timeout hides a crash-on-boot behind a timeout.
	if ! kill -0 "${FX_PID}" 2>/dev/null; then
		echo "::error::fixture forge exited during startup"
		cat "${LOG}" || true
		exit 1
	fi

	sleep 1
done

if [ "${ready}" -ne 1 ]; then
	echo "::error::fixture forge did not answer ${BASE}/health within 30s"
	cat "${LOG}" || true
	exit 1
fi
echo "Fixture forge ready (pid=${FX_PID})."

occ() { php "${NC_ROOT}/occ" "$@"; }

# Point both forge adapters at the fixture, and allow the fetch: Nextcloud
# refuses requests to local addresses unless told otherwise, which would make
# every fixture install fail as a network error rather than as a finding.
occ config:app:set versioniq forge.forgejo.api_base --value="${BASE}/api/v1" > /dev/null
occ config:app:set versioniq forge.forgejo.web_base --value="${BASE}" > /dev/null
occ config:app:set versioniq forge.github.api_base --value="${BASE}" > /dev/null
occ config:app:set versioniq forge.github.web_base --value="${BASE}" > /dev/null
occ config:system:set allow_local_remote_servers --value=true --type=boolean > /dev/null

# Point the App Store at the fixture too. Reaching the real catalogue
# (~12.4 MB from garm3.nextcloud.com) is what left discovery and version
# listing timing out at 20s while every other endpoint answered in 60ms — the
# forge double already removed that dependency for forge sources, and this
# removes the last one, so the suite stops asserting on somebody else's uptime.
occ config:app:set versioniq appstore.api_base --value="${BASE}/appstore/api/v1" > /dev/null
occ config:app:set versioniq trusted_sources \
	--value='["github:ConductionNL/*","codeberg:Conduction/*","forgejo:fixtureowner/*","github:fixtureowner/*"]' > /dev/null

# Install the baseline fixture app (1.0.0) and bind it to the fixture source, so
# the install/downgrade specs have a real app to move between versions.
tmp="$(mktemp -d)"
tar -C "${tmp}" -xzf "${FX}/artifacts/fixtureapp-1.0.0.tar.gz"
rm -rf "${NC_ROOT}/apps/fixtureapp"
cp -r "${tmp}/fixtureapp" "${NC_ROOT}/apps/fixtureapp"
rm -rf "${tmp}"

# Pin the STORED version to what the files carry. Without this, re-running after
# an install-under-test leaves the app flagged as needing an upgrade, and every
# later spec fails on maintenance state rather than on its subject.
occ config:app:set fixtureapp installed_version --value=1.0.0 > /dev/null
occ app:enable fixtureapp > /dev/null
occ maintenance:mode --off > /dev/null 2>&1 || true
occ config:app:set versioniq source.fixtureapp \
	--value='{"kind":"github-release","forge":"forgejo","owner":"fixtureowner","repo":"fixtureapp","assetPattern":"*.tar.gz"}' > /dev/null

# PROVE THE APP CAN SEE THE FIXTURE, not merely that both are running. The specs
# probe /health directly from the browser context, which says nothing about
# whether PHP's own HTTP client is allowed to reach it — that is a separate
# permission (allow_local_remote_servers) and its own failure mode.
# ── The suite's own traffic was being throttled ───────────────────────────────
#
# Five specs failed on `apiRequestContext: Timeout 20000ms exceeded`, and one of
# them is `unauthenticated callers cannot list tokens` — whose handler,
# ApiController::listPats(), returns 403 on its first line and makes no outbound
# call at all. A handler that cannot be slow, timing out, means the REQUEST was
# delayed rather than the endpoint.
#
# That is Nextcloud's bruteforce protection doing its job: an e2e suite fires
# many deliberately-unauthenticated and wrong-credential requests from ONE IP,
# which is indistinguishable from an attack, so the responses get progressively
# delayed. The same thing was diagnosed on portaliq, where our own e2e runs
# tripped the throttle and the failures read as product defects.
#
# Disabling it is correct for a disposable CI instance and ONLY there: the
# behaviour being suppressed is a response to this suite's own traffic, not
# anything about the app under test. Nothing asserts on throttling.
occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean > /dev/null
occ config:system:set ratelimit.protection.enabled --value=false --type=boolean > /dev/null

# ── Warm the App Store catalogue before the clock starts ──────────────────────
#
# The discover specs search the real App Store. The FIRST call has to fetch and
# parse the whole catalogue, which is what pushed `discover(calendar)` past 20s
# — including the diagnostic request that was supposed to explain the failure.
# Later calls hit the cached payload, which is why the same endpoint is fast
# once anything has warmed it.
#
# Doing that here means the cost lands in setup, where it is allowed to be slow,
# instead of inside a test's timeout. NOT fatal: if the App Store is unreachable
# the discover specs still fail, and they should — this only stops a cold cache
# from being mistaken for a broken endpoint.
echo "Warming the App Store catalogue…"
if ! timeout 180 php "${NC_ROOT}/occ" versioniq:versions dashboard > /dev/null 2>&1; then
	echo "::warning::App Store warm-up did not complete in 180s; the discover specs may still time out."
fi

# ── Time the endpoints the suite times out on, from the runner ────────────────
#
# Disabling the bruteforce throttle did NOT clear the `Timeout 20000ms
# exceeded` failures, so that hypothesis is dead and guessing again is not a
# method. These curls measure the two endpoints directly, unauthenticated and
# authenticated, and print the server's own timing.
#
# ⚠️ READ WHAT THIS MEASURES: the UNAUTHENTICATED path. Both endpoints check
# authorization first, so these curls get a 401 in ~60ms without ever reaching
# the App Store fetch that makes the authenticated path slow. Measured
# 2026-08-19: pats 0.075s/0.062s, discover 0.061s/0.062s, all http=401.
#
# That is still worth keeping, because it settles one thing: the SERVER is not
# slow, and `pat-management:104` — itself an unauthenticated test — times out
# at 20s in Playwright against an endpoint curl answers in 60ms. So that
# failure is the shared `page.request` context queueing behind an in-flight
# browser request, not a slow handler.
#
# It is deliberately NOT extended with basic auth to measure the real path:
# basic-auth curl pays a bcrypt hash on every request and inflates every
# timing, which would turn this from a measurement into a misleading number.
echo "Timing the endpoints the e2e suite times out on…"
for ep in "ocs/v2.php/apps/versioniq/api/pats?format=json" "ocs/v2.php/apps/versioniq/api/discover?q=calendar&format=json"; do
	for call in 1 2; do
		t=$(curl -s -o /dev/null -m 60 -w '%{time_total} http=%{http_code}' \
			-H 'OCS-APIRequest: true' "http://localhost:8080/${ep}" 2>/dev/null || echo "TIMEOUT")
		printf '    call %s  %-58s %s\n' "${call}" "${ep:0:58}" "${t}"
	done
done

echo "Verifying the app resolves versions through the fixture…"
versions="$(occ versioniq:versions fixtureapp 2>&1 || true)"
if ! printf '%s' "${versions}" | grep -q '1\.'; then
	echo "::warning::the app listed no fixture versions; forge specs will fail rather than skip."
	printf '%s\n' "${versions}"
fi

echo "Forge fixture bootstrapped: fixtureapp bound to forgejo:fixtureowner/fixtureapp at ${BASE}"
