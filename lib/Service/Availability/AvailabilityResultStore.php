<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Availability;

use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCA\Versioniq\Service\Settings\SweepOutcome;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Stores the last availability sweep, on the AdvisoryResultStore pattern: the
 * snapshot as JSON under one app config key, the unix time of the sweep under
 * another. `checkedAt: null` means no sweep has completed, which the page and
 * the CLI must say out loud rather than read as "nothing to update".
 *
 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
 *
 * @psalm-api
 */
class AvailabilityResultStore {
	/** The sweep time is held under `availability.results.checkedAt`; see {@see StoredSnapshot}. */
	private const KEY = 'availability.results';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Saves a snapshot; one that cannot be encoded keeps the previous one.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @param array<string, array<string, mixed>> $updates
	 */
	public function save(array $updates, int $checkedAt): void {
		try {
			$this->snapshot()->write($updates, $checkedAt);
			$outcome = SweepOutcome::of($updates);
			$this->snapshot()->writeOutcome($outcome['status'], $outcome['errors']);
		} catch (\JsonException $error) {
			$this->logger->error('AvailabilityResultStore: could not encode the snapshot; keeping the previous one', [
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * Records a sweep that failed as a whole: the previous snapshot and its
	 * `checkedAt` stay, the outcome says `failed` with the attempt's time as `failedAt`.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function saveFailure(int $failedAt): void {
		$this->snapshot()->writeOutcome(SweepOutcome::FAILED, [], $failedAt);
	}

	/**
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @return array{updates: array<string, mixed>, checkedAt: ?int, failedAt: ?int, status: ?string, errors: list<string>}
	 */
	public function read(): array {
		try {
			$updates = $this->snapshot()->readMap();
		} catch (\JsonException $error) {
			$this->logger->warning('AvailabilityResultStore: the stored snapshot is not valid JSON; reporting it as never checked', [
				'message' => $error->getMessage(),
			]);

			return ['updates' => [], 'checkedAt' => null, 'failedAt' => null, 'status' => null, 'errors' => []];
		}

		return ['updates' => $updates ?? []] + $this->outcome(map: $updates);
	}

	/**
	 * The time of the last sweep that succeeded and the outcome of the last
	 * sweep. `checkedAt` is never a failed attempt's time: readers that show it
	 * without the status (occ, MCP) must not read a failure as freshly checked.
	 * A sweep that failed as a whole has its time in `failedAt`.
	 *
	 * A snapshot stored before outcomes were recorded gets the outcome its own
	 * entries give.
	 *
	 * @param array<string, mixed>|null $map The stored snapshot, null when there is none.
	 * @return array{checkedAt: ?int, failedAt: ?int, status: ?string, errors: list<string>}
	 */
	private function outcome(?array $map): array {
		$status = $this->snapshot()->status();
		$checkedAt = $map === null ? null : $this->snapshot()->checkedAt();
		if ($map === null && $status !== SweepOutcome::FAILED) {
			return ['checkedAt' => null, 'failedAt' => null, 'status' => null, 'errors' => []];
		}

		if ($status === null) {
			return ['checkedAt' => $checkedAt, 'failedAt' => null] + SweepOutcome::of($map ?? []);
		}

		return [
			'checkedAt' => $checkedAt,
			'failedAt' => $status === SweepOutcome::FAILED ? $this->snapshot()->failedAt() : null,
			'status' => $status,
			'errors' => $this->snapshot()->errors(),
		];
	}

	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
