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
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A weekly summary of advisories that are NOT urgent — apps with a security
 * history whose installed version is already safe.
 *
 * WHY A DIGEST RATHER THAN MORE NOTIFICATIONS. {@see AdvisoryNotifier} fires
 * immediately when an installed version is actually inside an affected range,
 * and that must stay rare enough to be read. Informational advisories are far
 * more numerous — the published feed averages several new records a month
 * across 53 packages — so notifying on each would train administrators to
 * dismiss the channel that carries the urgent ones.
 *
 * Like the urgent notifier, this class has NO dependency on any installer or
 * version-mutation service: it can only inform.
 *
 * @psalm-api
 */
class AdvisoryDigestNotifier {
	private const CONFIG_LAST_SENT = 'advisory.digest_last_sent';

	/** Seven days. */
	private const DIGEST_INTERVAL_SECONDS = 7 * 24 * 60 * 60;

	public function __construct(
		private AdminNotificationServices $admin,
		private IAppConfig $config,
		private AdvisorySettingsStore $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Sends the digest if one is due, and reports how many admins received it.
	 *
	 * Returns 0 — without sending — when the digest is disabled, when one was
	 * sent inside the last seven days, or when there is nothing informational
	 * to report. A digest that says "nothing to report" every week is how a
	 * channel stops being read.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}> $correlations
	 */
	public function sendIfDue(array $correlations, int $now): int {
		if (!$this->settings->isDigestEnabled()) {
			return 0;
		}

		$lastSent = $this->config->getValueInt(Application::APP_ID, self::CONFIG_LAST_SENT, 0);
		if ($lastSent > 0 && ($now - $lastSent) < self::DIGEST_INTERVAL_SECONDS) {
			return 0;
		}

		$informational = array_values(array_filter(
			$correlations,
			static fn (array $entry): bool => $entry['state'] === AdvisoryService::STATE_AVAILABLE
				&& $entry['advisories'] !== [],
		));
		if ($informational === []) {
			// Nothing to say. The clock is NOT advanced, so the first week
			// with something to report sends immediately rather than waiting
			// out a window that was consumed by silence.
			return 0;
		}

		$appCount = count($informational);
		$advisoryCount = array_sum(array_map(
			static fn (array $entry): int => count($entry['advisories']),
			$informational,
		));

		$fired = 0;
		foreach ($this->adminUids() as $uid) {
			if ($this->fire($uid, $appCount, $advisoryCount)) {
				$fired++;
			}
		}

		// Only record a send that actually reached someone. Advancing the
		// clock on a failed dispatch would suppress the next seven days of
		// digests as well.
		if ($fired > 0) {
			$this->config->setValueInt(Application::APP_ID, self::CONFIG_LAST_SENT, $now);
		}

		return $fired;
	}

	private function fire(string $uid, int $appCount, int $advisoryCount): bool {
		try {
			$notification = $this->admin->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setDateTime(new \DateTime())
				->setUser($uid)
				->setObject('advisory_digest', (string)$appCount)
				->setSubject('advisory_digest', [
					'apps' => $appCount,
					'advisories' => $advisoryCount,
				]);
			$this->admin->notificationManager->notify($notification);

			return true;
		} catch (Throwable $error) {
			$this->logger->warning('AdvisoryDigestNotifier: could not notify admin', [
				'user' => $uid,
				'message' => $error->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * @return list<string>
	 */
	private function adminUids(): array {
		$uids = [];
		foreach ($this->admin->groupManager->get('admin')?->getUsers() ?? [] as $user) {
			$uids[] = $user->getUID();
		}

		return $uids;
	}
}
