<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Alert;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Notification\Notifier;
use OCP\Notification\IManager;

/**
 * The English text of an alert, rendered by the app's own Notifier, so a
 * webhook reads what the bell says (releases-new-release-alerts, design D6:
 * a webhook has no recipient language).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
 */
class AlertText {
	public function __construct(
		private Notifier $notifier,
		private IManager $notificationManager,
	) {
	}

	/**
	 * The rendered subject and message; the subject id when it cannot be rendered.
	 *
	 * @param array<string, mixed> $parameters
	 */
	public function english(string $subject, array $parameters): string {
		$notification = $this->notificationManager->createNotification();
		try {
			$notification
				->setApp(Application::APP_ID)
				->setUser('webhook')
				->setDateTime(new \DateTime())
				->setObject('alert', $subject)
				->setSubject($subject, $parameters);
			$this->notifier->prepare($notification, 'en');
		} catch (\Throwable) {
			return $subject;
		}

		return trim($notification->getParsedSubject() . ' ' . $notification->getParsedMessage());
	}
}
