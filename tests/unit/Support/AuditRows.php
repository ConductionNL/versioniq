<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Support;

use OCA\Versioniq\Db\AuditEntry;

/**
 * Builds audit entries for the history and report tests.
 */
final class AuditRows {
	/**
	 * One audit entry.
	 *
	 * @param int    $id        The row id.
	 * @param string $createdAt `Y-m-d H:i:s` UTC.
	 * @param string $operation The operation.
	 * @param array  $fields    appId, from, to, status, message, advisories.
	 */
	public static function entry(int $id, string $createdAt, string $operation, array $fields = []): AuditEntry {
		$entry = new AuditEntry();
		$entry->setId($id);
		$entry->setActorUid($fields['actor'] ?? 'system');
		$entry->setAppId($fields['appId'] ?? 'fixtureapp');
		$entry->setOperation($operation);
		$entry->setFromVersion($fields['from'] ?? null);
		$entry->setToVersion($fields['to'] ?? null);
		$entry->setSourceId(null);
		$entry->setStatus($fields['status'] ?? 'success');
		$entry->setMessage($fields['message'] ?? null);
		$entry->setCreatedAt($createdAt);
		if (isset($fields['advisories']) === true) {
			$entry->setAdvisories(json_encode($fields['advisories']));
		}

		return $entry;
	}
}
