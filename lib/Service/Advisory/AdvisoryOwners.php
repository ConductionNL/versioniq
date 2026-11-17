<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AdminNotificationServices;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who owns an assigned advisory, and telling them (advisories-triage, design D3).
 *
 * An assignee is `user:{uid}` for a user in the admin group, or
 * `group:{gid}` for an existing group whose admin members are the owners:
 * every Versioniq endpoint is admin-only, so an owner who is not an admin
 * could not open what they own.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 *
 * @psalm-api
 */
class AdvisoryOwners {
	public function __construct(
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The assignee a request names, or null for none (unassign).
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 * @return ?array{type: string, id: string}
	 * @throws TriageRefusal When it names no admin user and no existing group.
	 */
	public function parse(string $assignee): ?array {
		if ($assignee === '') {
			return null;
		}

		[$type, $id] = array_pad(explode(':', $assignee, 2), 2, '');
		if ($type === 'user' && $id !== '' && $this->admin->groupManager->isAdmin($id) === true) {
			return ['type' => 'user', 'id' => $id];
		}

		if ($type === 'group' && $id !== '' && $this->admin->groupManager->groupExists($id) === true) {
			return ['type' => 'group', 'id' => $id];
		}

		throw TriageRefusal::invalid('assignee must be user:{uid} of an admin or group:{gid} of an existing group.');
	}

	/**
	 * The uids that own an advisory: the user, or the admin members of the group.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 * @param array{type: string, id: string} $assignee
	 * @return list<string>
	 */
	public function owners(array $assignee): array {
		if ($assignee['type'] === 'user') {
			return [$assignee['id']];
		}

		$owners = [];
		foreach ($this->admin->groupManager->get($assignee['id'])?->getUsers() ?? [] as $user) {
			if ($this->admin->groupManager->isAdmin($user->getUID()) === true) {
				$owners[] = $user->getUID();
			}
		}

		return $owners;
	}

	/**
	 * Sends `advisory_assigned` to every owner, naming the app, the advisory
	 * and the admin who assigned it; a failure for one owner is logged and
	 * does not stop the others.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 * @param array{type: string, id: string} $assignee
	 * @return int How many owners were notified.
	 */
	public function notify(array $assignee, TriageTarget $target): int {
		$notified = 0;
		foreach ($this->owners(assignee: $assignee) as $uid) {
			$notified += $this->notifyOne(uid: $uid, target: $target) === true ? 1 : 0;
		}

		return $notified;
	}

	/**
	 * Sends the notification to one owner.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 */
	private function notifyOne(string $uid, TriageTarget $target): bool {
		try {
			$notification = $this->admin->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime($this->admin->timeFactory->getDateTime())
				->setObject('advisory', $target->key())
				->setSubject('advisory_assigned', ['app' => $target->appId, 'advisory' => $target->advisoryId, 'by' => $target->actor])
				->setLink($this->admin->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]));
			$this->admin->notificationManager->notify($notification);

			return true;
		} catch (Throwable $error) {
			$this->logger->warning('AdvisoryOwners: could not notify an owner', ['user' => $uid, 'message' => $error->getMessage()]);

			return false;
		}
	}
}
