<?php

declare(strict_types=1);

// Minimal bootstrap for tests that only exercise pure-PHP classes from
// `lib/Service/Source/*` against mocked OCP interfaces. This avoids pulling
// in the full Nextcloud server bootstrap so tests can run in CI / locally
// without a checked-out Nextcloud server tree.

require_once __DIR__ . '/../vendor/autoload.php';

// Server-side classes an app never has in its own vendor tree, but which
// nextcloud/ocp references at CLASS-DEFINITION time.
//
// This file existed and was never loaded here. It matters because
// OCP\DB\QueryBuilder\IQueryBuilder derives its PARAM_* constants from
// Doctrine\DBAL\ParameterType, and doctrine/dbal is a dependency of the
// Nextcloud SERVER, not of an app — so any unit test that so much as
// type-hints IQueryBuilder died with `Class "Doctrine\DBAL\ParameterType" not
// found` before reaching an assertion.
//
// That stayed invisible because tests/unit/Repair was missing from the
// testsuite list in phpunit-unit-only.xml: the tests never ran, so the
// breakage never reported. Adding the directory surfaced seven errors at once.
require_once __DIR__ . '/stubs/server-internals.php';

// nextcloud/ocp ships interface stubs without composer autoload — register
// them manually so PHPUnit can build mocks for OCP\* interfaces.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\') || str_starts_with($class, 'NCU\\')) {
		$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});

// Integriq's connection-registry events (adopt-connection-registry).
// ConnectionReportService sends them by string class name behind class_exists
// (ADR-041), so Versioniq stays installable without integriq. The stubs mirror
// hydra connection-registry design D6 and integriq's own classes, and load only
// when the real classes are absent.
foreach (['ConnectionStatusReportedEvent', 'ConnectionRefreshRequestedEvent'] as $integriqStubEvent) {
	if (!class_exists('\\OCA\\Integriq\\Event\\' . $integriqStubEvent)) {
		require_once __DIR__ . '/stubs/Integriq/Event/' . $integriqStubEvent . '.php';
	}
}

unset($integriqStubEvent);
