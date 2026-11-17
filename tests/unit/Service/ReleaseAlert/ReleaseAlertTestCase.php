<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * App config held in memory, for the release alert tests.
 */
abstract class ReleaseAlertTestCase extends TestCase {
	/** @var array<string, string> */
	protected array $stored = [];

	/** @var list<array{0: AuditSubject, 1: string, 2: ?string}> */
	protected array $audited = [];

	/**
	 * Settings over the in-memory config, recording audit rows.
	 */
	protected function settings(): ReleaseAlertSettings {
		$config = $this->appConfig();
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $status, $message];
		});

		return new ReleaseAlertSettings($config, new ReleaseEventLog($config), $audit);
	}

	protected function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;

				return true;
			},
		);

		return $config;
	}

	/**
	 * A logged release.
	 *
	 * @return array{appId: string, version: string, sourceId: ?string, compatible: bool, releasedAt: ?int, detectedAt: int}
	 */
	protected static function event(string $appId, string $version, ?string $sourceId = 'github:ConductionNL/hermiq', ?int $releasedAt = null): array {
		return ['appId' => $appId, 'version' => $version, 'sourceId' => $sourceId, 'compatible' => true, 'releasedAt' => $releasedAt, 'detectedAt' => 1_790_000_000];
	}
}
