<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Readiness;

use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The last upgrade readiness report, with its targets and time, in app config
 * `readiness.results` (inventory-upgrade-readiness D4), on the
 * AvailabilityResultStore pattern. The page reads it and never lists versions
 * itself (issue #160).
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
class ReadinessResultStore {
	private const KEY = 'readiness.results';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Keep a report.
	 *
	 * @param array{server: string, php: string, apps: list<array<string, mixed>>} $report
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	public function save(array $report, int $checkedAt): void {
		try {
			$this->snapshot()->write($report, $checkedAt);
		} catch (\JsonException $error) {
			$this->logger->error('ReadinessResultStore: could not encode the report; keeping the previous one', [
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * The last report, or an empty one with no time when none ran.
	 *
	 * @return array{report: ?array<string, mixed>, checkedAt: ?int}
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	public function read(): array {
		try {
			$report = $this->snapshot()->readMap();
		} catch (\JsonException $error) {
			$this->logger->warning('ReadinessResultStore: the stored report is not valid JSON; reporting it as never checked', [
				'message' => $error->getMessage(),
			]);
			$report = null;
		}

		return ['report' => $report, 'checkedAt' => $report === null ? null : $this->snapshot()->checkedAt()];
	}

	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
