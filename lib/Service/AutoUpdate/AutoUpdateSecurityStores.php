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

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;

/**
 * The stored state the security side of automatic updates reads: the last
 * advisory snapshot with its sweep interval, the patch policy, and why the
 * last run held a security fix back.
 *
 * @psalm-api
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class AutoUpdateSecurityStores {
	public function __construct(
		public readonly AdvisoryResultStore $advisoryResults,
		public readonly AdvisorySettingsStore $advisorySettings,
		public readonly PatchPolicyStore $patchPolicyStore,
		public readonly SecurityHoldStore $securityHolds,
	) {
	}
}
