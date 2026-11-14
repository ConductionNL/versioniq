<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\ReleaseAlert;

use OCA\Versioniq\Service\Availability\AvailabilityResultStore;

/**
 * Stores an availability sweep, then logs and announces the releases it
 * holds against the snapshot it replaced (releases-new-release-alerts,
 * design D1). AvailabilityRefreshJob and `occ versioniq:updates --refresh`
 * both store their sweep through here.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
 */
class ReleaseSweepRecorder {
	public function __construct(
		private AvailabilityResultStore $store,
		private ReleaseNotifier $notifier,
	) {
	}

	/**
	 * The stored snapshot, as AvailabilityResultStore::read() gives it.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @return array{updates: array<string, mixed>, checkedAt: ?int}
	 */
	public function read(): array {
		return $this->store->read();
	}

	/**
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @param array<string, array<string, mixed>> $updates
	 */
	public function record(array $updates, int $checkedAt): void {
		$previous = $this->store->read();
		$this->store->save($updates, $checkedAt);

		$events = (new ReleaseEventDetector($checkedAt))->detect($previous['updates'] ?? [], $updates);
		if ($events !== []) {
			$this->notifier->notifyReleases($events);
		}
	}
}
