#!/usr/bin/env bash
# Bootstraps the fixture forge for e2e: builds artifacts, starts the fixture
# container on a shared docker network with the Nextcloud container, points the
# self-hosted forgejo forge at it, enables local-address fetches, allowlists the fixture
# repo, and installs+binds a baseline fixture app. Idempotent.
#
# Usage: bootstrap.sh <nc-container> [<network>] [<fixture-port>]
set -euo pipefail
CT="${1:-av-e2e}"
NET="${2:-av-net}"
PORT="${3:-9099}"
HERE="$(cd "$(dirname "$0")" && pwd)"

"$HERE/build-artifacts.sh" "$HERE/artifacts" >/dev/null

docker network create "$NET" >/dev/null 2>&1 || true
docker network connect "$NET" "$CT" 2>/dev/null || true
docker rm -f forge-fixture >/dev/null 2>&1 || true
docker run -d --name forge-fixture --network "$NET" -p "$PORT:9099" \
	-v "$HERE":/fx -w /fx node:20-alpine node server.mjs >/dev/null
# wait for readiness
for _ in $(seq 1 30); do
	docker exec "$CT" sh -c "curl -sf -m2 http://forge-fixture:9099/health" >/dev/null 2>&1 && break
	sleep 1
done

occ() { docker exec -u www-data "$CT" php occ "$@"; }

# Point the self-hosted forgejo forge at the fixture and allow local-address fetches.
occ config:app:set versioniq forge.forgejo.api_base --value="http://forge-fixture:9099/api/v1" >/dev/null
occ config:app:set versioniq forge.forgejo.web_base --value="http://forge-fixture:9099" >/dev/null
occ config:app:set versioniq forge.github.api_base --value="http://forge-fixture:9099" >/dev/null
occ config:app:set versioniq forge.github.web_base --value="http://forge-fixture:9099" >/dev/null
occ config:system:set allow_local_remote_servers --value=true --type=boolean >/dev/null
occ config:app:set versioniq trusted_sources \
	--value='["github:ConductionNL/*","codeberg:Conduction/*","forgejo:fixtureowner/*","github:fixtureowner/*"]' >/dev/null

# Install a baseline fixture app (1.0.0) and bind it to the fixture source.
tmp="$(mktemp -d)"; tar -C "$tmp" -xzf "$HERE/artifacts/fixtureapp-1.0.0.tar.gz"
docker exec "$CT" rm -rf /var/www/html/custom_apps/fixtureapp
docker cp "$tmp/fixtureapp" "$CT":/var/www/html/custom_apps/fixtureapp >/dev/null
docker exec -u root "$CT" chown -R www-data:www-data /var/www/html/custom_apps/fixtureapp
rm -rf "$tmp"
# Pin the stored version to the baseline the files carry, so re-running after a
# prior install-under-test does not leave the app in a "needs upgrade" state.
occ config:app:set fixtureapp installed_version --value=1.0.0 >/dev/null
occ app:enable fixtureapp >/dev/null
occ maintenance:mode --off >/dev/null 2>&1 || true
occ config:app:set versioniq source.fixtureapp \
	--value='{"kind":"github-release","forge":"forgejo","owner":"fixtureowner","repo":"fixtureapp","assetPattern":"*.tar.gz"}' >/dev/null

echo "forge fixture bootstrapped for $CT (fixtureapp bound to forgejo:fixtureowner/fixtureapp)"
