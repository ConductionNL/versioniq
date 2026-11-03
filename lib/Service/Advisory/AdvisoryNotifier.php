<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Raises a Nextcloud notification to administrators when a newly-published
 * advisory affects an installed or pinned version, and remembers which
 * (app, advisory) pairs it has already notified so a subsequent refresh does
 * not re-notify an unchanged advisory.
 *
 * This class deliberately has NO dependency on any installer/version-mutation
 * service: it can only notify. That is the structural guarantee behind the
 * spec's "the system MUST NOT auto-update or auto-unpin" requirement — the
 * notify path is physically incapable of changing a version.
 *
 * @psalm-api
 */
class AdvisoryNotifier {
	use ResolvesAdminUids;

	private const CONFIG_KEY = 'advisory.notified';

	public function __construct(
		private AdminNotificationServices $admin,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Notifies admins about advisories that newly affect a pinned/installed
	 * version since the last run. Returns the number of notifications fired.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}> $correlations
	 */
	public function notifyNewAdvisories(array $correlations): int {
		$alreadyNotified = $this->loadNotified();
		$currentKeys = [];
		$newKeys = [];
		$admins = $this->adminUids();

		foreach ($correlations as $appId => $correlation) {
			if ($correlation['state'] !== AdvisoryService::STATE_VULNERABLE) {
				continue;
			}
			$installedVersion = $correlation['installedVersion'] ?? '';
			foreach ($correlation['advisories'] as $advisory) {
				$key = $appId . ':' . $advisory['id'];
				$currentKeys[$key] = true;
				if (isset($alreadyNotified[$key])) {
					continue;
				}
				$newKeys[$key] = true;
				foreach ($admins as $uid) {
					$this->fire($uid, $appId, (string)$installedVersion, $advisory['id']);
				}
			}
		}

		// Persist the set of currently-active vulnerable keys (union with the
		// ones we just fired). Keys that dropped out — advisory resolved or app
		// upgraded — are forgotten, so if the same advisory ever re-appears it
		// is treated as newly-published and re-notifies.
		$persist = $currentKeys + $newKeys;
		$this->storeNotified(array_keys($persist));

		return count($newKeys);
	}

	private function fire(string $uid, string $appId, string $version, string $advisoryId): void {
		try {
			$notification = $this->admin->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime($this->admin->timeFactory->getDateTime())
				->setObject('advisory', $appId . ':' . $advisoryId)
				->setSubject('pinned_to_vulnerable', [
					'app' => $appId,
					'version' => $version,
					'advisory' => $advisoryId,
				]);
			$this->admin->notificationManager->notify($notification);
		} catch (\Throwable $error) {
			$this->logger->warning('AdvisoryNotifier: failed to raise notification', [
				'app' => $appId,
				'advisory' => $advisoryId,
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * @return array<string, true>
	 */
	private function loadNotified(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) {
			return [];
		}
		$set = [];
		foreach ($decoded as $key) {
			if (is_string($key) && $key !== '') {
				$set[$key] = true;
			}
		}

		return $set;
	}

	/**
	 * @param list<string> $keys
	 */
	private function storeNotified(array $keys): void {
		sort($keys);
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode(array_values($keys), JSON_THROW_ON_ERROR),
		);
	}
}
