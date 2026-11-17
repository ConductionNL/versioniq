<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Release;

use OCA\Versioniq\Db\AuditEntryMapper;

/**
 * How installs of each version went on this instance (releases-version-facts
 * D2): the successful and failed `install` rows of the audit trail, per
 * target version. The picker shows them next to each version, and
 * `GET /api/app/{appId}/outcomes` returns them so another Versioniq can add
 * them to its own.
 */
final class InstallOutcomes {
	/** The counts of a version nothing was installed as. */
	public const NONE = ['succeeded' => 0, 'failed' => 0];

	public function __construct(
		private AuditEntryMapper $audit,
	) {
	}

	/**
	 * The counts per version of one app.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return array<string, array{succeeded: int, failed: int}>
	 */
	public function forApp(string $appId): array {
		return $this->audit->countOutcomesByVersion($appId);
	}

	/**
	 * Each version entry with its `outcomes`; a version never installed here
	 * carries zero of each.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @param list<array<string, mixed>> $versions
	 * @return list<array<string, mixed>>
	 */
	public function stamp(array $versions, string $appId): array {
		$counts = $this->forApp($appId);

		return array_map(
			static function (array $entry) use ($counts): array {
				$entry['outcomes'] = $counts[(string)($entry['version'] ?? '')] ?? self::NONE;
				return $entry;
			},
			$versions
		);
	}
}
