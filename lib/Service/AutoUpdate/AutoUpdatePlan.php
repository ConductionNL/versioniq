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
 * What one automatic-update run will do: one member per app, in the order
 * they run (autoupdate-run-batching D1). Update groups (D2) are not built yet;
 * until they are, every member stands alone.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdatePlan {
	/**
	 * @param list<AutoUpdateMember> $members The members, in run order.
	 */
	public function __construct(
		public readonly array $members,
	) {
	}

	/**
	 * Every member that will install, in run order.
	 *
	 * @return list<AutoUpdateMember>
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function installable(): array {
		return array_values(array_filter(
			$this->members,
			static fn (AutoUpdateMember $member): bool => $member->willInstall()
		));
	}
}
