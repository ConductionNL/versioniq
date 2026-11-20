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

use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicySource;
use OCA\Versioniq\Service\Pin\SkipList;
use OCA\Versioniq\Service\Policy\PolicyStore;

/**
 * The stored state automatic updates run on: the per-app policies, the pins
 * that hold an app back, the global settings (on/off, window, time zone) and
 * the ledger of versions already attempted, the update groups, the versions
 * an admin skipped, the security side (see AutoUpdateSecurityStores), which
 * the `security` level and the patch policy read, the proposed updates with
 * their approvals and declines, where the settings and policies come from
 * when `config.php` names a policy file; and, for the overview, the plan
 * snapshot and the admins' minimum-age exceptions (releases-minimum-age).
 *
 * @psalm-api
 */
final class AutoUpdateStores {
	public function __construct(
		public readonly PolicyStore $policyStore,
		public readonly PinStore $pinStore,
		public readonly AutoUpdateSettingsStore $settingsStore,
		public readonly AttemptLedger $attemptLedger,
		public readonly AutoUpdateSecurityStores $security,
		public readonly UpdateGroupStore $groupStore,
		// Proposed updates and the admins' decisions on them; optional and
		// last so existing callers keep working, the container always passes it.
		public readonly ?ProposalStore $proposalStore = null,
		public readonly ?SkipList $skipList = null,
		// Where settings and policies come from when config.php names a policy
		// file; without it the stores are the only source.
		public readonly ?PolicySource $policySource = null,
		public readonly ?AutoUpdatePlanStore $planStore = null,
		public readonly ?MinAgeExceptionStore $minAgeExceptions = null,
	) {
	}
}
