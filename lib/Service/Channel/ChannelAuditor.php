<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Channel;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\IUserSession;

/**
 * Records a channel change in the audit trail as operation `settings`, by the signed-in admin.
 *
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
 */
class ChannelAuditor {
	public function __construct(
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Records one change: the app it concerns (`versioniq` for the default) and the old and new value in words.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function changed(string $appId, string $message): void {
		$this->auditLogger->record(
			new AuditSubject($this->userSession->getUser()?->getUID() ?? '', $appId, AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
			$message,
		);
	}
}
