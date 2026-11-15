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

/**
 * Works out which apps have a new release between two availability
 * snapshots (releases-new-release-alerts, design D1).
 *
 * A new release is a newest version higher than the one the previous
 * snapshot recorded for that app. An app the sweep could not reach, an app
 * the previous snapshot did not hold, and the very first snapshot set a
 * baseline and raise nothing.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
 *
 * @psalm-type ReleaseEvent = array{appId: string, version: string, sourceId: ?string, compatible: bool, releasedAt: ?int, detectedAt: int}
 */
class ReleaseEventDetector {
	/**
	 * @param int $detectedAt When the sweep being compared was stored.
	 */
	public function __construct(
		private int $detectedAt,
	) {
	}

	/**
	 * The new releases in $current against $previous, in the order of $current.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @param array<string, mixed> $previous The previous snapshot's apps, empty for the first sweep.
	 * @param array<string, mixed> $current The snapshot just stored.
	 * @return list<ReleaseEvent>
	 */
	public function detect(array $previous, array $current): array {
		$events = [];
		foreach ($current as $appId => $entry) {
			$newest = self::newest($entry);
			$before = self::newest($previous[$appId] ?? null);
			if ($newest === null || $before === null || !version_compare($newest, $before, '>')) {
				continue;
			}

			$events[] = [
				'appId' => (string)$appId,
				'version' => $newest,
				'sourceId' => is_string($entry['sourceId'] ?? null) ? $entry['sourceId'] : null,
				'compatible' => ($entry['newestCompatibleVersion'] ?? null) === $newest,
				'releasedAt' => null,
				'detectedAt' => $this->detectedAt,
			];
		}

		return $events;
	}

	/**
	 * The newest version an entry recorded, or null when it holds none or
	 * records an error, so it neither raises nor serves as a baseline.
	 */
	private static function newest(mixed $entry): ?string {
		if (!is_array($entry) || ($entry['error'] ?? null) !== null) {
			return null;
		}

		$newest = $entry['newestVersion'] ?? null;

		return is_string($newest) && $newest !== '' ? $newest : null;
	}
}
