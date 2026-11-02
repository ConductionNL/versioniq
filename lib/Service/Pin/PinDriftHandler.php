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

use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\NotifiesAdmins;
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
	use NotifiesAdmins;
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

		$this->notifyAdmins($this->driftNotice($appId, [
			'pinnedVersion' => $pin->version,
			'observedVersion' => $installedVersion,
		]));
	}

	/**
	 * The `pin_drift` notice for an app; see "Admins are notified".
	 *
	 * @param array{pinnedVersion: string, observedVersion: string} $versions
	 */
	private function driftNotice(string $appId, array $versions): AdminNotice {
		return (new AdminNotice())
			->withObject('pin_drift', $appId)
			->withSubject('pin_drift', ['app' => $appId] + $versions)
			->loggedAs('PinDriftHandler: failed to raise drift notification', ['appId' => $appId]);
	}
}
