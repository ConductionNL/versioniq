<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * What the minimum-age planner judges for one app: the app with its level,
 * minimum age and the moment ages are measured at, the installed version,
 * and the versions its source lists, by version.
 *
 * @spec openspec/changes/releases-minimum-age
 */
final class AgeQuery {
	/**
	 * @param array<string, array{version: string, releasedAt?: ?string}> $byVersion
	 */
	private function __construct(
		public readonly PlanTarget $target,
		public readonly string $installedVersion,
		public readonly array $byVersion,
	) {
	}

	/**
	 * @param list<array{version: string, releasedAt?: ?string}> $entries The versions the app's source lists
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	public static function of(PlanTarget $target, string $installedVersion, array $entries): self {
		$byVersion = [];
		foreach ($entries as $entry) {
			$byVersion[$entry['version']] = $entry;
		}

		return new self($target, $installedVersion, $byVersion);
	}

	/**
	 * The moment ages are measured at: the run's, else now.
	 */
	public function now(): \DateTimeImmutable {
		return $this->target->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}
}
