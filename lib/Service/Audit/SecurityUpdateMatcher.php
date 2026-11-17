<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Audit;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Service\Advisory\AdvisoryExposureTracker;

/**
 * Picks the installs and server updates that fixed an advisory (D4).
 *
 * An `install` or `server_update` entry with status `success` is a security
 * update when an `advisory_resolved` entry with how `update` exists for the
 * same app and the same `to_version`, created after it and before the next
 * install of that app. The rows themselves are never changed; the fixed
 * advisories are taken from those resolved entries.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
class SecurityUpdateMatcher {
	/** Operations that change what runs. */
	public const UPDATE_OPERATIONS = ['install', AdvisoryExposureTracker::OPERATION_SERVER_UPDATE];

	/**
	 * The security updates among the entries, each with its fixed advisories.
	 *
	 * @param list<AuditEntry> $entries Entries in any order; resolutions may lie after the installs of interest.
	 *
	 * @return list<array{entry: AuditEntry, fixedAdvisories: list<array{id: string, severity: string}>}>
	 *               In the entries' time order.
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
	 */
	public function match(array $entries): array {
		$sorted = self::sorted(entries: $entries);
		$result = [];
		foreach ($sorted as $index => $entry) {
			if (in_array($entry->getOperation(), self::UPDATE_OPERATIONS, true) === false
				|| $entry->getStatus() !== AuditLogger::STATUS_SUCCESS
				|| (string)$entry->getToVersion() === ''
			) {
				continue;
			}

			$fixed = $this->fixedBy(sorted: $sorted, index: $index);
			if ($fixed !== []) {
				$result[] = ['entry' => $entry, 'fixedAdvisories' => $fixed];
			}
		}

		return $result;
	}

	/**
	 * The advisories resolved by update after the install at `$index` and
	 * before the next update of the same app.
	 *
	 * @param list<AuditEntry> $sorted The entries, oldest first.
	 * @param int              $index  The install's position.
	 *
	 * @return list<array{id: string, severity: string}>
	 */
	private function fixedBy(array $sorted, int $index): array {
		$install = $sorted[$index];
		$fixed   = [];
		$count   = count($sorted);
		for ($next = $index + 1; $next < $count; $next++) {
			$entry = $sorted[$next];
			if ($entry->getAppId() !== $install->getAppId()) {
				continue;
			}

			if (in_array($entry->getOperation(), self::UPDATE_OPERATIONS, true) === true) {
				break;
			}

			if ($entry->getOperation() !== AdvisoryExposureTracker::OPERATION_RESOLVED
				|| $entry->getMessage() !== AdvisoryExposureTracker::HOW_UPDATE
				|| $entry->getToVersion() !== $install->getToVersion()
			) {
				continue;
			}

			foreach ($entry->decodedAdvisories() ?? [] as $advisory) {
				$fixed[] = ['id' => (string)($advisory['id'] ?? ''), 'severity' => (string)($advisory['severity'] ?? 'unknown')];
			}
		}

		return $fixed;
	}

	/**
	 * The entries oldest first, by creation time and then id.
	 *
	 * @param list<AuditEntry> $entries The entries.
	 *
	 * @return list<AuditEntry>
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
	 */
	public static function sorted(array $entries): array {
		$sorted = array_values($entries);
		usort(
			$sorted,
			static fn (AuditEntry $a, AuditEntry $b): int => [$a->getCreatedAt(), (int)$a->getId()] <=> [$b->getCreatedAt(), (int)$b->getId()]
		);
		return $sorted;
	}
}
