<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Reactivation\ReactivationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly comparison of every app and the server with what Versioniq last saw,
 * for the changes no event reported: files replaced by hand, a restored
 * backup, an update while Versioniq was off, or a disable during a server
 * upgrade before Versioniq's listener was loaded.
 *
 * The first run only stores the baseline. Separate from
 * {@see PinReconcileJob}, which stays a daily pass over pinned apps.
 *
 * @psalm-import-type SeenState from SeenStateStore
 * @psalm-api
 */
class ExternalChangeReconcileJob extends TimedJob {
	public const INTERVAL_SECONDS = 3600;

	public function __construct(
		ITimeFactory $time,
		private SeenStateStore $seenState,
		private ExternalChangeRecorder $recorder,
		private ReactivationService $reactivation,
		private IAppManager $appManager,
		private IAppConfig $appConfig,
		private ServerVersionProvider $serverVersion,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/audit-external-changes
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$this->reconcile();
		} catch (\Throwable $error) {
			$this->logger->error('ExternalChangeReconcileJob: check failed', ['message' => $error->getMessage()]);
		}
	}

	/**
	 * Compares, records what changed, and stores what is now seen.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function reconcile(): void {
		$now = $this->currentApps();
		$server = $this->serverVersion->current();
		$before = $this->seenState->read();

		if ($before === null) {
			// The first run: a baseline, and nothing recorded.
			$this->seenState->write(['serverVersion' => $server, 'apps' => $now, 'disabledByUpdate' => []]);

			return;
		}

		foreach ($now as $appId => $app) {
			$seen = $before['apps'][$appId] ?? null;
			if ($seen === null || $seen['version'] !== $app['version']) {
				$this->recorder->recordUpdate($appId, $seen['version'] ?? null, $app['version'], ExternalChangeRecorder::HOURLY_TRIGGER, ExternalChangeRecorder::SYSTEM);
			}
		}
		foreach (array_diff_key($before['apps'], $now) as $appId => $seen) {
			$this->recorder->recordUpdate($appId, $seen['version'], null, ExternalChangeRecorder::HOURLY_TRIGGER, ExternalChangeRecorder::SYSTEM);
		}

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

		foreach ($now as $appId => $app) {
			$seen = $before['apps'][$appId] ?? null;
			if ($seen !== null && $seen['enabled'] && !$app['enabled']) {
				$this->reactivation->appDisabled($appId, $seen, $before['serverVersion'], ExternalChangeRecorder::SYSTEM, ExternalChangeRecorder::HOURLY_TRIGGER);
			}
		}
	}

	/**
	 * Every app with an installed version, enabled or not.
	 *
	 * @return array<string, array{version: string, enabled: bool}>
	 */
	private function currentApps(): array {
		$enabled = array_flip($this->appManager->getEnabledApps());
		$apps = [];
		/** @var mixed $version */
		foreach ($this->appConfig->searchValues('installed_version') as $appId => $version) {
			if (is_scalar($version) && (string)$version !== '') {
				$apps[$appId] = ['version' => (string)$version, 'enabled' => isset($enabled[$appId])];
			}
		}
		ksort($apps);

		return $apps;
	}
}
