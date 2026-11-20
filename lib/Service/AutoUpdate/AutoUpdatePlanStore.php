<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stores the last auto-update plan snapshot (releases-minimum-age D4), on the
 * AdvisoryResultStore pattern: the per-app plans as JSON under
 * `autoupdate.plan`, the unix time they were worked out under
 * `autoupdate.plan.checkedAt`. A snapshot that cannot be encoded keeps the
 * previous one, and `checkedAt: null` means no plan was ever worked out.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 *
 * @psalm-api
 */
class AutoUpdatePlanStore {
	public const KEY = 'autoupdate.plan';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Saves the plans; plans that cannot be encoded keep the previous snapshot.
	 *
	 * @param array<string, array<string, mixed>> $plans Per app id, its plan entry.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function save(array $plans, int $checkedAt): void {
		try {
			$this->snapshot()->write($plans, $checkedAt);
		} catch (\JsonException $error) {
			$this->logger->error('AutoUpdatePlanStore: could not encode the plan; keeping the previous one', [
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * The stored plans and when they were worked out; an unreadable snapshot
	 * reads as never planned.
	 *
	 * @return array{plans: array<string, mixed>, checkedAt: ?int}
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function read(): array {
		try {
			$plans = $this->snapshot()->readMap();
		} catch (\JsonException $error) {
			$this->logger->warning('AutoUpdatePlanStore: the stored plan is not valid JSON; reporting it as never planned', [
				'message' => $error->getMessage(),
			]);

			return ['plans' => [], 'checkedAt' => null];
		}

		if ($plans === null) {
			return ['plans' => [], 'checkedAt' => null];
		}

		return ['plans' => $plans, 'checkedAt' => $this->snapshot()->checkedAt()];
	}

	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
