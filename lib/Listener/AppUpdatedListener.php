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

use OCA\Versioniq\Kernel\Contracts\PinDriftHandlerInterface;
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
		private PinDriftHandlerInterface $driftHandler,
	) {
	}

	/**
	 * On an app update, compares the freshly-installed version against any
	 * pin for that app; see "Drift detection" (listener path).
	 *
	 * @spec openspec/specs/version-pinning/spec.md
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
	}
}
