<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Raises the `auto_update_success` / `auto_update_failure` admin notification
 * for one attempted install performed by
 * {@see \OCA\Versioniq\BackgroundJob\AutoUpdateJob}; see "Every auto-update
 * outcome is reported". Every admin-group member is notified, linking back
 * into the Versioniq admin settings page — mirrors
 * {@see \OCA\Versioniq\Service\Pin\PinDriftHandler::notifyAdmins()}.
 *
 * @psalm-api
 */
class AutoUpdateNotifier {
	use ResolvesAdminUids;

	public function __construct(
		private IManager $notificationManager,
		private IGroupManager $groupManager,
		private ITimeFactory $timeFactory,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Notifies admins that an auto-update installed successfully; see "Every
	 * auto-update outcome is reported" ("Success notification").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function notifySuccess(string $appId, ?string $fromVersion, string $toVersion): void {
		$this->fireAll($appId, $toVersion, 'auto_update_success', [
			'app' => $appId,
			'fromVersion' => $fromVersion ?? '',
			'toVersion' => $toVersion,
		]);
	}

	/**
	 * Notifies admins that an auto-update attempt failed, carrying the
	 * classified category/hint; see "Every auto-update outcome is reported"
	 * ("Failure notification carries the classification").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function notifyFailure(string $appId, string $targetVersion, string $category, string $hint): void {
		$this->fireAll($appId, $targetVersion, 'auto_update_failure', [
			'app' => $appId,
			'targetVersion' => $targetVersion,
			'category' => $category,
			'hint' => $hint,
		]);
	}

	/**
	 * @param array<string, string> $subjectParameters
	 */
	private function fireAll(string $appId, string $version, string $subject, array $subjectParameters): void {
		// Links into the Versioniq admin settings page.
		$link = $this->urlGenerator->linkToRouteAbsolute(
			'settings.AdminSettings.index',
			['section' => Application::APP_ID],
		);

		foreach ($this->adminUids() as $uid) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification
					->setApp(Application::APP_ID)
					->setUser($uid)
					->setDateTime($this->timeFactory->getDateTime())
					->setObject('auto_update', $appId . ':' . $version)
					->setSubject($subject, $subjectParameters)
					->setLink($link);
				$this->notificationManager->notify($notification);
			} catch (\Throwable $error) {
				$this->logger->warning('AutoUpdateNotifier: failed to raise notification', [
					'appId' => $appId,
					'subject' => $subject,
					'uid' => $uid,
					'message' => $error->getMessage(),
				]);
			}
		}
	}
}
