<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\ReleaseAlert;

use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use Psr\Log\LoggerInterface;

/**
 * Sends every admin a `new_release` notification for each release inside
 * the scope (releases-new-release-alerts, design D2).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 *
 * @psalm-import-type ReleaseEvent from ReleaseEventDetector
 */
class ReleaseNotifier {
	use NotifiesAdmins;
	use ResolvesAdminUids;

	public function __construct(
		private AdminNotificationServices $admin,
		private ReleaseAlertSettings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Logs the releases, then notifies the admins of those inside the scope.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 * @param list<ReleaseEvent> $events
	 */
	public function notifyReleases(array $events): void {
		$this->settings->logReleases($events);
		foreach ($events as $event) {
			if (!$this->settings->announces($event)) {
				continue;
			}

			$this->notifyAdmins((new AdminNotice())
				->withSubject('new_release', [
					'app' => $event['appId'],
					'version' => $event['version'],
					'sourceId' => $event['sourceId'] ?? '',
					'compatible' => $event['compatible'] ? '1' : '0',
				])
				->withObject('new_release', $event['appId'] . ':' . $event['version'])
				->loggedAs('ReleaseNotifier: failed to raise notification', [
					'appId' => $event['appId'],
					'version' => $event['version'],
				]));
		}
	}
}
