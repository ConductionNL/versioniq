<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Snapshot;

use OCP\AppFramework\Http;

/**
 * Runs a rollback plan from the command line (install-bulk-rollback design
 * D5): one install per step through the standard installer, from the
 * snapshot's source, in plan order. A `downgrade` step passes allowDowngrade,
 * because the plan was acknowledged; a `pinned` step runs only when the pins
 * are moved, with overridePin=repin. The run stops at the first failure unless
 * told to continue; the steps it did not reach, and pinned steps whose pin
 * stays, count as skipped.
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
		private SnapshotInstaller $installer,
	) {
	}

	/**
	 * Whether a step installs anything in a run.
	 *
	 * @param RollbackStep $step
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	public static function isRunnable(array $step, bool $movePins): bool {
		return $step['kind'] === 'downgrade'
			|| $step['kind'] === 'upgrade'
			|| ($movePins && $step['kind'] === 'pinned');
	}

	/**
	 * Whether a step moves an app to a lower version.
	 *
	 * @param RollbackStep $step
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	public static function isDowngrade(array $step): bool {
		return $step['from'] !== null && $step['to'] !== null && version_compare($step['to'], $step['from'], '<');
	}

	/**
	 * Whether a run reports a step: every move, and every pinned step.
	 *
	 * @param RollbackStep $step
	 */
	private static function isReported(array $step, RunMode $mode): bool {
		return $step['kind'] === 'pinned' || self::isRunnable($step, $mode->movePins);
	}

	/**
	 * Run (or check) the steps of a plan.
	 *
	 * @param list<RollbackStep> $steps
	 * @return list<StepOutcome> One outcome per move and per pinned step, in order.
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
	 */
	public function run(array $steps, RunMode $mode): array {
		$outcomes = [];
		$stopped = false;
		foreach ($steps as $step) {
			if (!self::isReported($step, $mode)) {
				continue;
			}
			$outcome = $stopped ? self::outcome($step, ['outcome' => 'skipped']) : $this->runStep($step, $mode);
			$stopped = $stopped || ($outcome['outcome'] === 'failed' && !$mode->continue);
			$outcomes[] = $outcome;
			$mode->notify($outcome);
		}

		return $outcomes;
	}

	/**
	 * Counts of a run's outcomes.
	 *
	 * @param list<array{outcome: string}> $outcomes
	 * @return array{succeeded: int, failed: int, skipped: int}
	 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
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
	 * One step of a run that has not stopped: skipped when it does not run
	 * (a pinned step whose pin stays, or anything but a downgrade on a dry run),
	 * else installed or checked.
	 *
	 * @param RollbackStep $step
	 * @return StepOutcome
	 */
	private function runStep(array $step, RunMode $mode): array {
		if (!self::isRunnable($step, $mode->movePins) || ($mode->dryRun && !self::isDowngrade($step))) {
			return self::outcome($step, ['outcome' => 'skipped']);
		}
		$result = $this->installer->install($step, $mode->dryRun);
		$statusCode = (int)($result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR);
		/** @var array<array-key, mixed> $payload */
		$payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

		return self::outcome($step, ['outcome' => self::verdict($statusCode, $mode), 'statusCode' => $statusCode, 'payload' => $payload]);
	}

	/**
	 * `failed` outside 2xx, else `checked` on a dry run and `succeeded` on a real one.
	 *
	 * @return 'succeeded'|'failed'|'checked'
	 */
	private static function verdict(int $statusCode, RunMode $mode): string {
		if ($statusCode < Http::STATUS_OK || $statusCode >= Http::STATUS_MULTIPLE_CHOICES) {
			return 'failed';
		}

		return $mode->dryRun ? 'checked' : 'succeeded';
	}

	/**
	 * @param RollbackStep $step
	 * @param array{outcome: 'succeeded'|'failed'|'skipped'|'checked', statusCode?: ?int, payload?: array<array-key, mixed>} $result
	 * @return StepOutcome
	 */
	private static function outcome(array $step, array $result): array {
		return [
			'appId' => $step['appId'],
			'kind' => $step['kind'],
			'from' => $step['from'],
			'to' => $step['to'],
			'outcome' => $result['outcome'],
			'statusCode' => $result['statusCode'] ?? null,
			'payload' => $result['payload'] ?? [],
		];
	}
}
