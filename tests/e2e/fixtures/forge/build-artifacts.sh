#!/usr/bin/env bash
# Builds minimal but valid Nextcloud app tarballs (+ .sha256 siblings) that the
# fixture forge serves, so an install can be driven end-to-end without a real
# forge. Each archive is `{appid}/appinfo/info.xml` — the single-root layout the
# external installer expects.
set -euo pipefail
OUT="${1:?usage: build-artifacts.sh <outdir>}"
mkdir -p "$OUT"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

infoxml() { # appid version
  cat <<XML
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
	<id>$1</id>
	<name>Forge Fixture App</name>
	<summary>Fixture app served by the e2e forge double</summary>
	<description>A minimal, valid Nextcloud app used only by the Versioniq e2e suite to exercise forge installs.</description>
	<version>$2</version>
	<licence>EUPL-1.2</licence>
	<author>Conduction B.V.</author>
	<namespace>ForgeFixtureApp</namespace>
	<category>tools</category>
	<bugs>https://example.test/issues</bugs>
	<dependencies>
		<nextcloud min-version="31" max-version="35"/>
	</dependencies>
</info>
XML
}

make() { # filename appid infoversion [extrabyte]
  local file="$1" appid="$2" ver="$3" extra="${4:-}"
  rm -rf "$tmp/build"; mkdir -p "$tmp/build/$appid/appinfo"
  infoxml "$appid" "$ver" > "$tmp/build/$appid/appinfo/info.xml"
  # optional extra byte to make otherwise-identical archives differ (TOFU tamper)
  [ -n "$extra" ] && printf '%s' "$extra" > "$tmp/build/$appid/appinfo/.variant"
  tar -C "$tmp/build" -czf "$OUT/$file" "$appid"
  ( cd "$OUT" && sha256sum "$file" | awk '{print $1}' > "$file.sha256" )
}

make_with_migration() { # filename appid infoversion migrationclass
  local file="$1" appid="$2" ver="$3" mig="$4"
  rm -rf "$tmp/build"; mkdir -p "$tmp/build/$appid/appinfo" "$tmp/build/$appid/lib/Migration"
  infoxml "$appid" "$ver" > "$tmp/build/$appid/appinfo/info.xml"
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

echo "built artifacts in $OUT:"; ls -1 "$OUT" | grep -v '.sha256$'
