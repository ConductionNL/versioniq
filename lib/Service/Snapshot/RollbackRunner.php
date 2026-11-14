<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;

/**
 * Runs a rollback plan from the command line (install-bulk-rollback design
 * D5): one install per step through the standard installer, from the
 * snapshot's source, in plan order. A `downgrade` step passes allowDowngrade,
 * because the plan was acknowledged; a `pinned` step runs only when the pins
 * are moved, with overridePin=repin. The run stops at the first failure unless
 * told to continue; the steps it did not reach count as skipped.
 *
 * A dry run installs nothing: it runs a dry-run install for every downgrade
 * it would make and reports the migrations each would orphan.
 *
 * @psalm-import-type RollbackStep from RollbackPlanner
 * @psalm-type StepOutcome = array{appId: string, kind: string, from: ?string, to: ?string, outcome: 'succeeded'|'failed'|'skipped'|'checked', statusCode: ?int, payload: array<array-key, mixed>}
 * @psalm-api
 */
class RollbackRunner {
	public function __construct(
		private InstallerService $installerService,
	) {
	}

	/**
	 * Whether a step installs anything in a run.
	 *
	 * @param RollbackStep $step
	 */
	public static function isRunnable(array $step, bool $movePins): bool {
		return $step['kind'] === 'downgrade'
			|| $step['kind'] === 'upgrade'
			|| ($movePins && $step['kind'] === 'pinned');
	}

	/**
	 * Run (or check) the steps of a plan.
	 *
	 * @param list<RollbackStep> $steps
	 * @param callable(array<string, mixed>): void|null $onStep Called after each step with its outcome.
	 * @return list<StepOutcome> One outcome per runnable step, in order.
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	public function run(array $steps, bool $dryRun, bool $movePins, bool $continue, ?callable $onStep = null): array {
		$outcomes = [];
		$stopped = false;
		foreach ($steps as $step) {
			if (!self::isRunnable($step, $movePins)) {
				continue;
			}
			$isDowngrade = $step['from'] !== null && $step['to'] !== null && version_compare($step['to'], $step['from'], '<');
			if ($stopped || ($dryRun && !$isDowngrade)) {
				$outcome = self::outcome($step, 'skipped', null, []);
			} else {
				$outcome = $this->install($step, $dryRun, $isDowngrade);
				$stopped = $outcome['outcome'] === 'failed' && !$continue;
			}
			$outcomes[] = $outcome;
			if ($onStep !== null) {
				$onStep($outcome);
			}
		}

		return $outcomes;
	}

	/**
	 * Counts of a run's outcomes.
	 *
	 * @param list<array{outcome: string}> $outcomes
	 * @return array{succeeded: int, failed: int, skipped: int}
	 */
	public static function counts(array $outcomes): array {
		$counts = ['succeeded' => 0, 'failed' => 0, 'skipped' => 0];
		foreach ($outcomes as $outcome) {
			if (isset($counts[$outcome['outcome']])) {
				$counts[$outcome['outcome']]++;
			}
		}

		return $counts;
	}

	/**
	 * @param RollbackStep $step
	 * @return StepOutcome
	 */
	private function install(array $step, bool $dryRun, bool $isDowngrade): array {
		$result = $this->installerService->installAppVersion(new InstallRequest(
			new InstallTarget($step['appId'], (string)$step['to'], $step['sourceId']),
			new InstallOptions(false, $dryRun, false, $isDowngrade),
			new PinChoice($step['kind'] === 'pinned' ? InstallerService::OVERRIDE_PIN_REPIN : null, false),
		));
		$statusCode = (int)($result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR);
		/** @var array<array-key, mixed> $payload */
		$payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
		$ok = $statusCode >= Http::STATUS_OK && $statusCode < Http::STATUS_MULTIPLE_CHOICES;

		return self::outcome($step, $ok ? ($dryRun ? 'checked' : 'succeeded') : 'failed', $statusCode, $payload);
	}

	/**
	 * @param RollbackStep $step
	 * @param 'succeeded'|'failed'|'skipped'|'checked' $outcome
	 * @param array<array-key, mixed> $payload
	 * @return StepOutcome
	 */
	private static function outcome(array $step, string $outcome, ?int $statusCode, array $payload): array {
		return [
			'appId' => $step['appId'],
			'kind' => $step['kind'],
			'from' => $step['from'],
			'to' => $step['to'],
			'outcome' => $outcome,
			'statusCode' => $statusCode,
			'payload' => $payload,
		];
	}
}
