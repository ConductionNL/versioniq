<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pin;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Shared drift-response path used by both {@see \OCA\Versioniq\Listener\AppUpdatedListener}
 * (immediate, event-driven) and {@see \OCA\Versioniq\BackgroundJob\PinReconcileJob}
 * (daily safety net): compares a pinned app's live installed version against
 * its pin, records drift on the pin (idempotently, via {@see PinStore::markDrift()}),
 * and — only on a genuinely new drift — notifies every admin-group member.
 *
 * This class deliberately has no dependency on any installer/version-mutation
 * service: it can only read the pin and notify. That is the structural
 * guarantee behind "the system MUST NOT reinstall anything autonomously" —
 * the drift-response path is physically incapable of changing a version.
 *
 * @psalm-api
 */
class PinDriftHandler {
	use ResolvesAdminUids;

	public function __construct(
		private PinStore $pinStore,
		private IManager $notificationManager,
		private IGroupManager $groupManager,
		private ITimeFactory $timeFactory,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Compares the live installed version against the app's pin and, on a
	 * newly detected mismatch, records and notifies; see "Drift detection"
	 * and "Drift response — notify and offer re-pin".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function handle(string $appId, string $installedVersion): void {
		$pin = $this->pinStore->get($appId);
		if ($pin === null) {
			return;
		}
		if ($pin->version === $installedVersion) {
			// Matches the pin — nothing drifted (also covers the "adjusted before
			// finalize" self-install case: the pin was already moved to this
			// version, so this compares equal and never raises a false alarm).
			return;
		}

		$driftedAt = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		$newlyDrifted = $this->pinStore->markDrift($appId, new PinDrift($installedVersion, $driftedAt));
		if (!$newlyDrifted) {
			// Either already recorded for this exact drifted version, or the
			// pin disappeared between get() and markDrift() — either way, no
			// notification is due.
			return;
		}

		$this->notifyAdmins($appId, $pin->version, $installedVersion);
	}

	private function notifyAdmins(string $appId, string $pinnedVersion, string $observedVersion): void {
		// Links into the Versioniq admin settings page; see "Admins are notified".
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
					->setObject('pin_drift', $appId)
					->setSubject('pin_drift', [
						'app' => $appId,
						'pinnedVersion' => $pinnedVersion,
						'observedVersion' => $observedVersion,
					])
					->setLink($link);
				$this->notificationManager->notify($notification);
			} catch (\Throwable $error) {
				$this->logger->warning('PinDriftHandler: failed to raise drift notification', [
					'appId' => $appId,
					'uid' => $uid,
					'message' => $error->getMessage(),
				]);
			}
		}
	}
}
