<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Listener;

use OCA\Versioniq\Service\Reactivation\ReactivationService;
use OCP\App\Events\AppDisableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * An app switched off through Nextcloud's app manager: the Apps page,
 * `occ app:disable`, or a server upgrade that disables an app which does not
 * declare the new major. {@see ReactivationService} decides whether that was
 * on purpose or left by an update. Disables this listener misses (before
 * Versioniq was loaded during an upgrade) are found by the hourly check.
 *
 * @psalm-api
 *
 * @template-implements IEventListener<AppDisableEvent>
 */
class AppDisabledListener implements IEventListener {
	public function __construct(
		private ReactivationService $reactivation,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 */
	public function handle(Event $event): void {
		if (!($event instanceof AppDisableEvent)) {
			return;
		}

		try {
			$this->reactivation->disabledNow($event->getAppId());
		} catch (\Throwable $error) {
			// Best-effort: disabling an app must never fail on the history.
			$this->logger->warning('AppDisabledListener: could not record a disable', ['message' => $error->getMessage()]);
		}
	}
}
