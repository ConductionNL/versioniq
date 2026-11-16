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
 * One app the planner is asked to place: its id and its policy level, before
 * anything about its installed or available versions is known.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class PlanTarget {
	public function __construct(
		public readonly string $appId,
		public readonly string $level,
	) {
	}
}
