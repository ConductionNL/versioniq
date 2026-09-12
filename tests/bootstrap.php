<?php

declare(strict_types=1);

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * The server's own tests/bootstrap.php loads lib/base.php, which declares `OC`
 * and builds `\OC::$server` before it throws on an uninstalled tree. That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * container lookup in the code under test hits a container that knows none of
 * this app's registrations and autowires from scratch. On 2026-09-08 that
 * recursion took 19 GB of RAM in openregister. The decision therefore has to be
 * made BEFORE the server bootstrap is loaded, and the only cheap signal is the
 * `installed` flag in config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function versioniq_nc_root_is_installed(string $ncRoot): bool {
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it inside a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}

// This config boots the Nextcloud server: the OC_App call below has no meaning
// without it. So a missing or uninstalled tree is a hard stop with a message
// that names the alternative, not a require_once warning followed by a fatal
// half-way through the server bootstrap. Use tests/phpunit-unit-only.xml for the
// suite that runs without a server.
$versioniqNcRoot = dirname(__DIR__, 3);

if (is_file($versioniqNcRoot . '/tests/bootstrap.php') === false) {
	fwrite(
		STDERR,
		sprintf(
			"[versioniq/tests/bootstrap] No Nextcloud server at %s, and this config needs one.\n"
			. "  Run from inside a Nextcloud checkout, or use tests/phpunit-unit-only.xml for the standalone suite.\n",
			$versioniqNcRoot
		)
	);
	exit(1);
}

if (versioniq_nc_root_is_installed($versioniqNcRoot) === false) {
	fwrite(
		STDERR,
		sprintf(
			"[versioniq/tests/bootstrap] Nextcloud tree at %s is not installed (config/config.php lacks installed => true).\n"
			. "  Loading it would leave a half-built server container behind, so the run stops here.\n"
			. "  Use tests/phpunit-unit-only.xml for the standalone suite, or point this at an installed instance.\n",
			$versioniqNcRoot
		)
	);
	exit(1);
}

require_once $versioniqNcRoot . '/tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

\OC_App::loadApp(OCA\Versioniq\AppInfo\Application::APP_ID);
OC_Hook::clear();
