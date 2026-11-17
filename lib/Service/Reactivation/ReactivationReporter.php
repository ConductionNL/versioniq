<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Reactivation;

use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use Psr\Log\LoggerInterface;

/**
 * Tells every admin about an app an update left disabled, and logs a
 * re-enable that failed.
 *
 * @psalm-api
 */
class ReactivationReporter {
	use NotifiesAdmins;
	use ResolvesAdminUids;

	public function __construct(
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Raises the left-disabled notification for every admin.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @param array<string, string> $subject The notification's subject parameters.
	 */
	public function leftDisabled(string $appId, array $subject): void {
		$this->notifyAdmins((new AdminNotice())
			->withObject('app_left_disabled', $appId)
			->withSubject('app_left_disabled', $subject)
			->loggedAs('ReactivationService: failed to raise the left-disabled notification', ['appId' => $appId]));
	}

	/**
	 * Logs that an app an update left disabled could not be enabled again.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function enableFailed(string $appId, string $message): void {
		$this->logger->warning('ReactivationService: could not re-enable an app an update left disabled', ['appId' => $appId, 'message' => $message]);
	}
}
