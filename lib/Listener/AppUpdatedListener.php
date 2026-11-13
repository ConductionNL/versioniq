<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Listener;

use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCP\App\Events\AppUpdateEvent;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Immediate drift detection for every app update flowing through
 * `OC\App\AppManager` — the web Apps page, `occ app:update`, or any other
 * tool built on `IAppManager`. `AppUpdateEvent` is post-hoc and
 * non-cancellable (Nextcloud core has no pre-update veto hook — see
 * design.md), so this listener can only detect and report; the daily
 * {@see \OCA\Versioniq\BackgroundJob\PinReconcileJob} is the safety net for updates
 * that bypass the event entirely (e.g. performed while this app was
 * disabled).
 *
 * @psalm-api
 *
 * @template-implements IEventListener<AppUpdateEvent>
 */
class AppUpdatedListener implements IEventListener {
	public function __construct(
		private IAppManager $appManager,
		private PinDriftHandler $driftHandler,
		// Records the change for every app, pinned or not (audit-external-changes).
		private ?ExternalChangeRecorder $recorder = null,
	) {
	}

	/**
	 * On an app update, compares the freshly-installed version against any
	 * pin for that app; see "Drift detection" (listener path). Then records
	 * the change in the history for every app.
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/changes/audit-external-changes
	 */
	public function handle(Event $event): void {
		if (!($event instanceof AppUpdateEvent)) {
			return;
		}

		$appId = $event->getAppId();
		$installedVersion = $this->appManager->getAppVersion($appId, false);
		if ($installedVersion === '') {
			return;
		}

		$this->driftHandler->handle($appId, $installedVersion);

		// After the drift check, so a pinned app keeps its pin_drift row and
		// gets this one next to it: that one says the pin broke, this one
		// says what changed and how.
		try {
			$this->recorder?->versionChanged($appId, $installedVersion);
		} catch (\Throwable) {
			// Recording is best-effort: an update must never fail on it.
		}
	}
}
