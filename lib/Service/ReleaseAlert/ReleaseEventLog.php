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

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The log of detected releases, newest first, at most 200 entries, kept in
 * app config under `release_alerts.log` (releases-new-release-alerts, design D1).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
 *
 * @psalm-import-type ReleaseEvent from ReleaseEventDetector
 */
class ReleaseEventLog {
	public const KEY = 'release_alerts.log';

	public const MAX_ENTRIES = 200;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * Adds releases in front of the log and drops what falls past the cap.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @param list<ReleaseEvent> $events In detection order; the last one ends up first.
	 */
	public function append(array $events): void {
		if ($events === []) {
			return;
		}

		$entries = array_slice([...array_reverse($events), ...$this->all()], 0, self::MAX_ENTRIES);
		$this->config->setValueString(Application::APP_ID, self::KEY, json_encode($entries, JSON_THROW_ON_ERROR));
	}

	/**
	 * Every logged release, newest first; an unreadable log reads as empty.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @return list<ReleaseEvent>
	 */
	public function all(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY, '');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		/** @var list<ReleaseEvent> */
		return array_values(array_filter($decoded, static fn (mixed $entry): bool => is_array($entry) && is_string($entry['appId'] ?? null) && is_string($entry['version'] ?? null)));
	}
}
