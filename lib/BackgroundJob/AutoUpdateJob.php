<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Policy-driven auto-update sweep, once per update window. Turns Versioniq from a repair
 * tool into bounded, observable update automation; see "Nightly policy
 * execution through the standard installer".
 *
 * For every app with a policy level other than `none`: skips pinned apps
 * entirely (no source query), lists versions from the app's bound source,
 * selects the highest qualifying version via {@see CandidateSelector}, and —
 * unless that exact (appId, version) was already attempted — installs it
 * through {@see InstallerService::installAppVersion()}, the same verified
 * path (signature/integrity checks, backup/restore, structured outcomes,
 * audit trail) as every other install in this app. Every attempt is recorded
 * in the {@see AttemptLedger} (never-retry) and reported via
 * {@see AutoUpdateNotifier} (success or failure — a no-op app is never
 * notified).
 *
 * No-ops entirely (no source queries, no installs) when the global kill
 * switch is off or the current server time falls outside the configured
 * window; each app is processed in its own try/catch so one failing app
 * never stops the sweep.
 *
 * @psalm-api
 */
class AutoUpdateJob extends TimedJob {
	/**
	 * Wakes every quarter hour and lets the window decide. A 24-hour interval
	 * kept the job near the time cron first picked it up, so a job first run
	 * at 14:00 missed a 01:00-05:00 window for months (#429). The opening key
	 * below keeps it to one sweep per window.
	 */
	private const INTERVAL_SECONDS = 15 * 60;

	public function __construct(
		ITimeFactory $time,
		private PolicyStore $policyStore,
		private PinStore $pinStore,
		private InstallerService $installerService,
		private IAppManager $appManager,
		private CandidateSelector $candidateSelector,
		private AttemptLedger $attemptLedger,
		private AutoUpdateSettingsStore $settingsStore,
		private AutoUpdateNotifier $notifier,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	protected function run($argument): void {
		if (!$this->settingsStore->isEnabled()) {
			// Kill switch off — policies remain stored but inert; see "Global
			// kill switch and window" ("Kill switch inert-but-stored").
			return;
		}

		$zoneName = $this->settingsStore->getTimeZoneName();
		$now = $this->time->getDateTime('now', new \DateTimeZone($zoneName !== '' ? $zoneName : 'UTC'));
		$window = $this->settingsStore->getWindow();
		if (!AutoUpdateWindow::isWithin($window, $now)) {
			// Outside the configured window — see "Disabled or outside the
			// window is a no-op".
			return;
		}

		$openingKey = AutoUpdateWindow::openingKey($window, $now);
		if ($this->settingsStore->hasSweptWindow($openingKey)) {
			// Already swept this window; the job wakes every quarter hour.
			return;
		}
		$this->settingsStore->markWindowSwept($openingKey);

		foreach ($this->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			try {
				$this->processApp($appId, $policy->level);
			} catch (\Throwable $error) {
				// Per-app isolation — see "the job MUST proceed to the next app
				// after any failure".
				$this->logger->warning('AutoUpdateJob: failed to process an app', [
					'appId' => $appId,
					'message' => $error->getMessage(),
				]);
			}
		}
	}

	private function processApp(string $appId, string $level): void {
		if ($this->pinStore->get($appId) !== null) {
			// Pinned apps are skipped entirely, before any source query; see
			// "Pinned app skipped".
			return;
		}

		if (!$this->installerService->isManageableApp($appId)) {
			return;
		}

		$installedVersion = null;
		try {
			$installed = $this->appManager->getAppVersion($appId, false);
			$installedVersion = $installed !== '' ? $installed : null;
		} catch (\Throwable) {
			$installedVersion = null;
		}
		if ($installedVersion === null) {
			return;
		}

		$result = $this->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return;
		}

		/** @var list<string> $available */
		$available = array_map(
			static fn (array $entry): string => (string)$entry['version'],
			$result['availableVersions'] ?? []
		);

		$candidate = $this->candidateSelector->select($installedVersion, $available, $level);
		if ($candidate === null) {
			return;
		}

		if ($this->attemptLedger->hasAttempted($appId, $candidate)) {
			// Never-retry — see "Failed attempt is not retried".
			return;
		}

		$this->attemptInstall($appId, $installedVersion, $candidate);
	}

	private function attemptInstall(string $appId, string $installedVersion, string $candidate): void {
		$installResult = $this->installerService->installAppVersion(
			$appId,
			$candidate,
			false,
			null,
			null,
			false,
			false,
			false,
			false,
		);

		$at = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		$statusCode = $installResult['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
		$payload = $installResult['payload'] ?? [];

		if ($statusCode >= Http::STATUS_OK && $statusCode < Http::STATUS_MULTIPLE_CHOICES) {
			$this->attemptLedger->record($appId, $candidate, AttemptLedger::OUTCOME_SUCCESS, $at);
			$toVersion = is_string($payload['installedVersion'] ?? null) && $payload['installedVersion'] !== ''
				? $payload['installedVersion']
				: $candidate;
			$this->notifier->notifySuccess($appId, $installedVersion, $toVersion);

			return;
		}

		$this->attemptLedger->record($appId, $candidate, AttemptLedger::OUTCOME_FAILURE, $at);
		$category = is_string($payload['category'] ?? null) ? $payload['category'] : 'unknown';
		$hint = is_string($payload['hint'] ?? null) ? $payload['hint'] : '';
		$this->notifier->notifyFailure($appId, $candidate, $category, $hint);
	}
}
