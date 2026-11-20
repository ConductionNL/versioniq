#!/usr/bin/env bash
# Builds minimal but valid Nextcloud app tarballs (+ .sha256 siblings) that the
# fixture forge serves, so an install can be driven end-to-end without a real
# forge. Each archive is `{appid}/appinfo/info.xml` — the single-root layout the
# external installer expects.
set -euo pipefail
OUT="${1:?usage: build-artifacts.sh <outdir>}"
mkdir -p "$OUT"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

infoxml() { # appid version [min-version max-version]
  local ns=ForgeFixtureApp name="Forge Fixture App"
  [ "$1" = fixtureapp2 ] && ns=ForgeFixtureAppTwo name="Forge Fixture App Two"
  cat <<XML
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
	<id>$1</id>
	<name>$name</name>
	<summary>Fixture app served by the e2e forge double</summary>
	<description>A minimal, valid Nextcloud app used only by the Versioniq e2e suite to exercise forge installs.</description>
	<version>$2</version>
	<licence>EUPL-1.2</licence>
	<author>Conduction B.V.</author>
	<namespace>$ns</namespace>
	<category>tools</category>
	<bugs>https://example.test/issues</bugs>
	<dependencies>
		<nextcloud min-version="${3:-31}" max-version="${4:-35}"/>
	</dependencies>
</info>
XML
}

# A Composer 2 manifest naming one bundled PHP library, so the bundled-library
# check (advisories-bundled-libraries) has something to ask the OSV double about.
# The OSV double in server.mjs knows an advisory for exactly this version.
manifest() { # appdir
  mkdir -p "$1/vendor/composer"
  cat > "$1/vendor/composer/installed.json" <<'JSON'
{
    "packages": [
        {
            "name": "vendor/library",
            "version": "2.4.3",
            "version_normalized": "2.4.3.0",
            "type": "library"
        }
    ],
    "dev": false,
    "dev-package-names": []
}
JSON
}

make() { # filename appid infoversion [extrabyte]
  local file="$1" appid="$2" ver="$3" extra="${4:-}"
  rm -rf "$tmp/build"; mkdir -p "$tmp/build/$appid/appinfo"
  infoxml "$appid" "$ver" > "$tmp/build/$appid/appinfo/info.xml"
  [ "$appid" = fixtureapp ] && manifest "$tmp/build/$appid"
  # optional extra byte to make otherwise-identical archives differ (TOFU tamper)
  [ -n "$extra" ] && printf '%s' "$extra" > "$tmp/build/$appid/appinfo/.variant"
  tar -C "$tmp/build" -czf "$OUT/$file" "$appid"
  ( cd "$OUT" && sha256sum "$file" | awk '{print $1}' > "$file.sha256" )
}

make_with_migration() { # filename appid infoversion migrationclass
  local file="$1" appid="$2" ver="$3" mig="$4"
  rm -rf "$tmp/build"; mkdir -p "$tmp/build/$appid/appinfo" "$tmp/build/$appid/lib/Migration"
  infoxml "$appid" "$ver" > "$tmp/build/$appid/appinfo/info.xml"
  manifest "$tmp/build/$appid"
  # A no-op migration step so this version's archive carries a Version*.php the
  # migration-diff can compare against an installed copy that lacks it.
  cat > "$tmp/build/$appid/lib/Migration/$mig.php" <<PHP
<?php
namespace OCA\\ForgeFixtureApp\\Migration;
use OCP\\Migration\\SimpleMigrationStep;
class $mig extends SimpleMigrationStep {}
PHP
  tar -C "$tmp/build" -czf "$OUT/$file" "$appid"
  ( cd "$OUT" && sha256sum "$file" | awk '{print $1}' > "$file.sha256" )
}

# Well-formed releases for fixtureapp: below, at, and above the 1.0.0 baseline.
make fixtureapp-0.9.0.tar.gz fixtureapp 0.9.0
make fixtureapp-1.0.0.tar.gz fixtureapp 1.0.0
make fixtureapp-1.0.1.tar.gz fixtureapp 1.0.1
make fixtureapp-1.1.0.tar.gz fixtureapp 1.1.0
# A second app on the same release line, for specs that update two apps.
make fixtureapp2-1.0.0.tar.gz fixtureapp2 1.0.0
make fixtureapp2-1.0.1.tar.gz fixtureapp2 1.0.1
make fixtureapp2-1.1.0.tar.gz fixtureapp2 1.1.0
make fixtureapp2-1.2.0.tar.gz fixtureapp2 1.2.0
# 1.0.2 declares Nextcloud 28-30 only: a maximum below every server the suite
# runs on, so it is refused unless forced (install-force-incompatible). It is
# not in the default release set; the spec that needs it adds it.
rm -rf "$tmp/build"; mkdir -p "$tmp/build/fixtureapp/appinfo"
infoxml fixtureapp 1.0.2 28 30 > "$tmp/build/fixtureapp/appinfo/info.xml"
tar -C "$tmp/build" -czf "$OUT/fixtureapp-1.0.2-maxold.tar.gz" fixtureapp
( cd "$OUT" && sha256sum fixtureapp-1.0.2-maxold.tar.gz | awk '{print $1}' > fixtureapp-1.0.2-maxold.tar.gz.sha256 )
# TOFU tamper: same tag 1.0.1, different bytes (simulates a rewritten release).
make fixtureapp-1.0.1-tampered.tar.gz fixtureapp 1.0.1 tampered
# Integrity failures: appId mismatch, version mismatch (tag says 1.0.1, info says 9.9.9).
make fixtureapp-wrongid.tar.gz notfixtureapp 1.0.1
make fixtureapp-wrongversion.tar.gz fixtureapp 9.9.9
# 1.2.0 ships a migration step the 1.0.0 baseline lacks, so a downgrade off it
# has an orphaned migration for the diff to name.
make_with_migration fixtureapp-1.2.0.tar.gz fixtureapp 1.2.0 Version1020Date20260101000000

# 1.3.0 ships a migration that throws in changeSchema, so the finalize phase
# fails and the installer must revert to the previous version.
make_bad_finalize() { # filename appid version
  local file="$1" appid="$2" ver="$3"
  rm -rf "$tmp/build"; mkdir -p "$tmp/build/$appid/appinfo" "$tmp/build/$appid/lib/Migration"
  infoxml "$appid" "$ver" > "$tmp/build/$appid/appinfo/info.xml"
  manifest "$tmp/build/$appid"
  cat > "$tmp/build/$appid/lib/Migration/Version1030Date20260101000000.php" <<PHP
<?php
namespace OCA\\ForgeFixtureApp\\Migration;
use Closure;
use OCP\\Migration\\IOutput;
use OCP\\Migration\\SimpleMigrationStep;
class Version1030Date20260101000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput \\\$output, Closure \\\$schemaClosure, array \\\$options) {
		throw new \\RuntimeException('fixture: deliberate finalize failure');
	}
}
PHP
  tar -C "$tmp/build" -czf "$OUT/$file" "$appid"
  ( cd "$OUT" && sha256sum "$file" | awk '{print $1}' > "$file.sha256" )
}
make_bad_finalize fixtureapp-1.3.0.tar.gz fixtureapp 1.3.0

# A self-signed certificate for the fixture's https listener. The OSV base
# (`advisory.osv_api_base`) is https only, so the OSV double must speak TLS; the
# bootstrap scripts import this certificate into Nextcloud's trust store.
# Generated once and kept, so a re-run does not invalidate an imported copy.
if [ ! -s "$OUT/tls/fixture.crt" ] || [ ! -s "$OUT/tls/fixture.key" ]; then
  mkdir -p "$OUT/tls"
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -subj "/CN=forge-fixture" \
    -addext "subjectAltName=DNS:forge-fixture,DNS:localhost,IP:127.0.0.1" \
    -keyout "$OUT/tls/fixture.key" -out "$OUT/tls/fixture.crt" >/dev/null 2>&1
  chmod 644 "$OUT/tls/fixture.key" "$OUT/tls/fixture.crt"
fi

echo "built artifacts in $OUT:"; ls -1 "$OUT" | grep -v '.sha256$' | grep -v '^tls$'
