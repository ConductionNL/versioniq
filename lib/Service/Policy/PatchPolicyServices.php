<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\Service\Audit\AdminChangeAuditInterface;

/**
 * What the patch policy endpoints work with: the stored policy, the
 * sentences that state it, and the audit log every change is written to.
 *
 * @psalm-api
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class PatchPolicyServices {
	public function __construct(
		public readonly PatchPolicyStore $store,
		public readonly PatchPolicyStatement $statement,
		public readonly AdminChangeAuditInterface $auditLogger,
	) {
	}
}
