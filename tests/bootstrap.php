<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../tests/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

\OC_App::loadApp(OCA\Versioniq\AppInfo\Application::APP_ID);
OC_Hook::clear();

// Integriq's connection-registry events (adopt-connection-registry). Loaded
// after the app so a real integriq on the server wins; the stubs only fill in
// when integriq is absent. Same stubs as tests/bootstrap-unit-only.php.
foreach (['ConnectionStatusReportedEvent', 'ConnectionRefreshRequestedEvent'] as $integriqStubEvent) {
	if (class_exists('\\OCP\\EventDispatcher\\Event') && !class_exists('\\OCA\\Integriq\\Event\\' . $integriqStubEvent)) {
		require_once __DIR__ . '/stubs/Integriq/Event/' . $integriqStubEvent . '.php';
	}
}

unset($integriqStubEvent);
