<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Readiness\ReadinessResultStore;
use OCA\Versioniq\Service\Readiness\UpgradeReadinessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one upgrade readiness check queued from the page and stores the
 * report (inventory-upgrade-readiness D4). The argument carries the two
 * targets: `{server: string, php: string}`.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
class UpgradeReadinessJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private UpgradeReadinessService $readiness,
		private ReadinessResultStore $resultStore,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `{server: string, php: string}`
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	protected function run($argument): void {
		$targets = self::targets($argument);
		if ($targets === null) {
			$this->logger->warning('UpgradeReadinessJob: queued without a server and PHP target; nothing checked');
			return;
		}

		try {
			$report = $this->readiness->check($targets['server'], $targets['php']);
			$this->resultStore->save($report, $this->time->getTime());
		} catch (Throwable $error) {
			$this->logger->error('UpgradeReadinessJob: the check failed: ' . $error->getMessage(), ['exception' => $error]);
		}
	}

	/**
	 * The two targets of the job argument, or null when one is missing.
	 *
	 * @return array{server: string, php: string}|null
	 */
	public static function targets(mixed $argument): ?array {
		$targets = ['server' => self::field($argument, 'server'), 'php' => self::field($argument, 'php')];
		if (in_array('', $targets, true)) {
			return null;
		}

		return $targets;
	}

	private static function field(mixed $argument, string $key): string {
		$value = is_array($argument) ? ($argument[$key] ?? null) : null;

		return is_string($value) ? trim($value) : '';
	}
}
