<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service;

use OCA\Versioniq\AppInfo\Application;

/**
 * Raises one linked notification per admin, each linking into the Versioniq
 * admin settings page; a failure for one admin is logged and does not stop
 * the others. The using class also uses ResolvesAdminUids and holds
 * `$notificationManager` (IManager), `$timeFactory` (ITimeFactory),
 * `$urlGenerator` (IURLGenerator) and `$logger` (LoggerInterface).
 */
trait NotifiesAdmins {
	private function notifyAdmins(AdminNotice $notice): void {
		$linked = $notice->withLink($this->urlGenerator->linkToRouteAbsolute(
			'settings.AdminSettings.index',
			['section' => Application::APP_ID],
		));

		foreach ($this->adminUids() as $uid) {
			$this->notifyAdmin($uid, $linked);
		}
	}

	private function notifyAdmin(string $uid, AdminNotice $notice): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime($this->timeFactory->getDateTime());
			$notice->applyTo($notification);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $error) {
			$this->logger->warning($notice->logMessage(), $notice->logContext($uid, $error));
		}
	}
}
