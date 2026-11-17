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
 * One managed, unpinned, installed app as the planner sees it: its level,
 * the version it runs and the versions its source offers.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class PlanSubject {
	/**
	 * @param list<string> $available The versions the app's source offers.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $level,
		public readonly string $installedVersion,
		public readonly array $available,
	) {
	}
}
