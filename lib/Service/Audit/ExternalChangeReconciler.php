<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
namespace OCA\Versioniq\Service\Audit;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Reactivation\DisabledApp;
use OCA\Versioniq\Service\Reactivation\ReactivationService;

/**
 * Compares every app and the server with what Versioniq last saw, records
 * what changed, and stores what is now seen; run hourly by
 * {@see \OCA\Versioniq\BackgroundJob\ExternalChangeReconcileJob}.
 *
 * @psalm-import-type SeenApp from SeenStateStore
 * @psalm-import-type SeenState from SeenStateStore
 * @psalm-api
 */
class ExternalChangeReconciler {
	public function __construct(
		private SeenStateStore $seenState,
		private ExternalChangeRecorder $recorder,
		private ReactivationService $reactivation,
		private ServerVersionProvider $serverVersion,
	) {
	}

	/**
	 * Compares, records what changed, and stores what is now seen.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function reconcile(): void {
		$now = $this->seenState->installedApps();
		$server = $this->serverVersion->current();
		$before = $this->seenState->read();

		if ($before === null) {
			// The first run: a baseline, and nothing recorded.
			$this->seenState->write(['serverVersion' => $server, 'apps' => $now, 'disabledByUpdate' => []]);

			return;
		}

		$this->recordVersionChanges($before, $now);

		// The new picture first, keeping the left-disabled marks of apps that
		// are still off; the disable handling below then compares against the
		// picture from before and adds its own marks.
		$this->seenState->write([
			'serverVersion' => $server,
			'apps' => $now,
			'disabledByUpdate' => array_filter(
				$before['disabledByUpdate'],
				static fn (int|string $appId): bool => isset($now[(string)$appId]) && !$now[(string)$appId]['enabled'],
				ARRAY_FILTER_USE_KEY,
			),
		]);

		$this->handleDisables($before, $now);
	}

	/**
	 * One `external_update` row per app whose version changed, appeared or went away.
	 *
	 * @param SeenState $before
	 * @param array<string, SeenApp> $now
	 */
	private function recordVersionChanges(array $before, array $now): void {
		foreach ($now as $appId => $app) {
			$seen = $before['apps'][$appId] ?? null;
			if ($seen === null || $seen['version'] !== $app['version']) {
				$this->recorder->recordUpdate(new AppVersionChange($appId, $seen['version'] ?? null, $app['version']), ChangeOrigin::hourly());
			}
		}
		foreach (array_diff_key($before['apps'], $now) as $appId => $seen) {
			$this->recorder->recordUpdate(new AppVersionChange($appId, $seen['version'], null), ChangeOrigin::hourly());
		}
	}

	/**
	 * Hands every app that went from enabled to disabled to the reactivation service.
	 *
	 * @param SeenState $before
	 * @param array<string, SeenApp> $now
	 */
	private function handleDisables(array $before, array $now): void {
		foreach ($now as $appId => $app) {
			$seen = $before['apps'][$appId] ?? null;
			if ($seen !== null && $seen['enabled'] && !$app['enabled']) {
				$this->reactivation->appDisabled(new DisabledApp($appId, $seen, $before['serverVersion']), ChangeOrigin::hourly());
			}
		}
	}
}
