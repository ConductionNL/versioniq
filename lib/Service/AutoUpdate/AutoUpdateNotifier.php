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
use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
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
	use NotifiesAdmins;
	use ResolvesAdminUids;

	public function __construct(
		private AdminNotificationServices $admin,
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
		$this->notifyAdmins($this->notice('auto_update_success', [
			'app' => $appId,
			'fromVersion' => $fromVersion ?? '',
			'toVersion' => $toVersion,
		])->withObject('auto_update', $appId . ':' . $toVersion));
	}

	/**
	 * Notifies admins that an auto-update attempt failed, carrying the
	 * classified category/hint; see "Every auto-update outcome is reported"
	 * ("Failure notification carries the classification").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function notifyFailure(string $appId, string $targetVersion, string $category, string $hint): void {
		$this->notifyAdmins($this->notice('auto_update_failure', [
			'app' => $appId,
			'targetVersion' => $targetVersion,
			'category' => $category,
			'hint' => $hint,
		])->withObject('auto_update', $appId . ':' . $targetVersion));
	}

	/**
	 * Announces a proposed update ahead of its window to every admin and every
	 * member of the notice group, each once, with both versions, the planned
	 * time and the release notes excerpt. The group gains no access by it:
	 * the text carries the notes itself, not only the link.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	public function notifyPlanned(ProposedUpdate $proposal, string $noticeGroup): void {
		$notice = $this->notice('auto_update_planned', [
			'app' => $proposal->appId,
			'fromVersion' => $proposal->fromVersion,
			'toVersion' => $proposal->toVersion,
			'plannedFor' => $proposal->plannedFor ?? '',
			'releaseNotes' => $proposal->releaseNotes,
		])->withObject('auto_update_planned', $proposal->appId . ':' . $proposal->toVersion)
			->withLink($this->admin->urlGenerator->linkToRouteAbsolute(
				'settings.AdminSettings.index',
				['section' => Application::APP_ID],
			));

		foreach ($this->plannedRecipients($noticeGroup) as $uid) {
			$this->notifyAdmin($uid, $notice);
		}
	}

	/**
	 * The admins, then the notice group's members, each once.
	 *
	 * @return list<string>
	 */
	private function plannedRecipients(string $noticeGroup): array {
		$uids = $this->adminUids();
		if ($noticeGroup !== '') {
			foreach ($this->admin->groupManager->get($noticeGroup)?->getUsers() ?? [] as $user) {
				$uids[] = $user->getUID();
			}
		}

		return array_values(array_unique($uids));
	}

	/**
	 * A notice with this subject, logged with its app and subject when it fails.
	 *
	 * @param array{app: string}&array<string, string> $parameters
	 */
	private function notice(string $subject, array $parameters): AdminNotice {
		return (new AdminNotice())
			->withSubject($subject, $parameters)
			->loggedAs('AutoUpdateNotifier: failed to raise notification', [
				'appId' => $parameters['app'],
				'subject' => $subject,
			]);
	}
}
