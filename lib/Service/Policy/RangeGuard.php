<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;

/**
 * Holds Versioniq's own installs to an app's policy level when the policy
 * says so ("Also hold manual installs to this level"). Nextcloud's own
 * updater is not held: that needs core changes.
 *
 * @spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
 * @psalm-api
 */
class RangeGuard {
	public function __construct(
		private PolicyStore $policyStore,
		private CandidateSelector $candidateSelector,
		private AuditLogger $auditLogger,
	) {
	}

	/**
	 * The level that refuses this install, or null when it may go ahead: the
	 * policy holds manual installs, the target is newer than installed, and it
	 * is outside the level. A downgrade is left to the downgrade guard.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
	 */
	public function refusingLevel(string $appId, string $installedVersion, string $targetVersion): ?string {
		$policy = $this->policyStore->get($appId);
		if ($policy === null || !$policy->holdsManualInstalls() || $installedVersion === '') {
			return null;
		}

		if (!version_compare($targetVersion, $installedVersion, '>')) {
			return null;
		}

		return $this->candidateSelector->withinLevel($installedVersion, $targetVersion, $policy->level) ? null : $policy->level;
	}

	/**
	 * Record an install that went ahead outside the level on an explicit override.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/auto-update-policies/spec.md#requirement-a-policy-can-hold-manual-installs-to-its-level
	 */
	public function recordOverride(string $actorUid, string $appId, string $level, string $installedVersion, string $targetVersion): void {
		$this->auditLogger->record(
			new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_RANGE_OVERRIDE),
			new AuditedVersions($installedVersion, $targetVersion, null),
			AuditLogger::STATUS_SUCCESS,
			sprintf('Installed %s outside the %s level on an explicit override.', $targetVersion, $level),
		);
	}
}
