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
 * One app the planner is asked to place: its id, its policy level and the
 * minimum age its versions must reach, before anything about its installed or
 * available versions is known.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 * @spec openspec/changes/releases-minimum-age
 */
final class PlanTarget {
	/**
	 * @param int $minAgeDays The minimum age in days (releases-minimum-age D1); 0 holds nothing.
	 * @param \DateTimeImmutable|null $now The moment ages are measured at; null is the current time.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $level,
		public readonly int $minAgeDays = 0,
		public readonly ?\DateTimeImmutable $now = null,
	) {
	}
}
