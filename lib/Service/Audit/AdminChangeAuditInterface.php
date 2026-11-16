<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

/**
 * The audit trail as an administrator's change reaches it: who changed what,
 * in one sentence, recorded as a success of the app itself.
 *
 * AuditLogger implements it. The settings, instance-connection and
 * patch-policy paths type against this role instead of the logger and its
 * value objects, so they do not add to AuditLogger's fan-in.
 *
 * @spec openspec/specs/audit-trail/spec.md
 */
interface AdminChangeAuditInterface {
	/** An instance setting changed from the Settings tab (#438). */
	public const OPERATION_SETTINGS = 'settings';
	/** A change to the patch policy (autoupdate-security-first D5). */
	public const OPERATION_PATCH_POLICY = 'patch_policy';
	/** A connection to another Versioniq instance was added, changed or removed (install-multi-instance-promotion D1). */
	public const OPERATION_INSTANCE_CONNECTION = 'instance_connection';

	/**
	 * Records an administrator's change of the app's own configuration.
	 *
	 * @param string $actorUid The administrator, '' when there is no session.
	 * @param string $operation One of the OPERATION_* constants of this interface.
	 * @param string $message What changed.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function recordAdminChange(string $actorUid, string $operation, string $message): void;
}
