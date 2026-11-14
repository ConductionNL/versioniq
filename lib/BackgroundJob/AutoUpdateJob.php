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
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Policy\Policy;
use OCP\AppFramework\Http;
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
 * through {@see \OCA\Versioniq\Service\InstallerService::installAppVersion()}, the same verified
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

	private LoggerInterface $logger;

	public function __construct(
		JobRuntime $runtime,
		private AutoUpdateStores $stores,
		private AutoUpdateInstall $install,
		private AutoUpdateNotifier $notifier,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	protected function run($argument): void {
		if (!$this->stores->settingsStore->isEnabled()) {
			// Kill switch off — policies remain stored but inert; see "Global
			// kill switch and window" ("Kill switch inert-but-stored").
			return;
		}

		$zoneName = $this->stores->settingsStore->getTimeZoneName();
		$now = $this->time->getDateTime('now', new \DateTimeZone($zoneName !== '' ? $zoneName : 'UTC'));
		$window = $this->stores->settingsStore->getWindow();
		if (!AutoUpdateWindow::isWithin($window, $now)) {
			// Outside the configured window — see "Disabled or outside the
			// window is a no-op".
			return;
		}

		$openingKey = AutoUpdateWindow::openingKey($window, $now);
		if ($this->stores->settingsStore->hasSweptWindow($openingKey)) {
			// Already swept this window; the job wakes every quarter hour.
			return;
		}
		$this->stores->settingsStore->markWindowSwept($openingKey);

		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			try {
				$this->processApp($appId, $policy, $now);
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

	private function processApp(string $appId, Policy $policy, \DateTimeInterface $now): void {
		if ($this->isSkipped($appId)) {
			return;
		}

		$installedVersion = $this->installedVersionOf($appId);
		$available = $installedVersion === null ? null : $this->availableVersions($appId);
		if ($installedVersion === null || $available === null) {
			return;
		}

		$candidate = $this->install->candidateSelector->select($installedVersion, $available, $policy->level);
		if ($candidate === null || $this->stores->attemptLedger->hasAttempted($appId, $candidate)) {
			// Never-retry — see "Failed attempt is not retried".
			return;
		}
		if ($this->isHeldBack($appId, $candidate, $policy, $now)) {
			return;
		}

		$this->attemptInstall(new InstallTarget($appId, $candidate, null), $installedVersion);
	}

	/**
	 * Whether an admin's decision or the notice period holds this version
	 * back, checked in the order of design D6: a declined version is skipped;
	 * with a notice period the version must be the stored proposal and its
	 * planned time must have come; an app that requires approval installs
	 * only the exact version an admin approved. Without notice, approval or
	 * declines nothing is held back.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	private function isHeldBack(string $appId, string $candidate, Policy $policy, \DateTimeInterface $now): bool {
		$proposals = $this->stores->proposalStore;
		if ($proposals === null) {
			return false;
		}
		if ($proposals->declineOf($appId, $candidate) !== null) {
			return true;
		}
		if ($this->stores->settingsStore->getNoticeDays() > 0 && !$this->noticeHasPassed($proposals->proposalFor($appId), $candidate, $now)) {
			return true;
		}

		return $policy->requireApproval && !$proposals->isApproved($appId, $candidate);
	}

	/**
	 * Whether this version was announced as the app's proposal and the
	 * opening it was planned for has come.
	 */
	private function noticeHasPassed(?ProposedUpdate $proposal, string $candidate, \DateTimeInterface $now): bool {
		if ($proposal === null || $proposal->toVersion !== $candidate || $proposal->plannedFor === null) {
			return false;
		}

		return new \DateTimeImmutable($proposal->plannedFor) <= $now;
	}

	/**
	 * Pinned apps are skipped entirely, before any source query (see "Pinned
	 * app skipped"), and so are apps Versioniq does not manage.
	 */
	private function isSkipped(string $appId): bool {
		return $this->stores->pinStore->get($appId) !== null
			|| !$this->install->installerService->isManageableApp($appId);
	}

	/** The installed version, or null when the app reports none or cannot be read. */
	private function installedVersionOf(string $appId): ?string {
		$installedVersion = null;
		try {
			$installed = $this->install->appManager->getAppVersion($appId, false);
			$installedVersion = $installed !== '' ? $installed : null;
		} catch (\Throwable) {
			$installedVersion = null;
		}

		return $installedVersion;
	}

	/**
	 * The versions the app's source lists, or null when it reported an error.
	 *
	 * @return list<string>|null
	 */
	private function availableVersions(string $appId): ?array {
		$result = $this->install->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return null;
		}

		/** @var list<string> $available */
		$available = array_map(
			static fn (array $entry): string => (string)$entry['version'],
			$result['availableVersions'] ?? []
		);

		return $available;
	}

	private function attemptInstall(InstallTarget $target, string $installedVersion): void {
		$installResult = $this->install->installerService->installAppVersion(new InstallRequest(
			$target,
			new InstallOptions(false, false, false, false),
			new PinChoice(null, false),
		));

		$at = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		$statusCode = $installResult['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
		$payload = $installResult['payload'] ?? [];

		if ($statusCode >= Http::STATUS_OK && $statusCode < Http::STATUS_MULTIPLE_CHOICES) {
			$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedger::OUTCOME_SUCCESS, $at);
			$toVersion = is_string($payload['installedVersion'] ?? null) && $payload['installedVersion'] !== ''
				? $payload['installedVersion']
				: $target->version;
			$this->notifier->notifySuccess($target->appId, $installedVersion, $toVersion);

			return;
		}

		$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedger::OUTCOME_FAILURE, $at);
		$category = is_string($payload['category'] ?? null) ? $payload['category'] : 'unknown';
		$hint = is_string($payload['hint'] ?? null) ? $payload['hint'] : '';
		$this->notifier->notifyFailure($target->appId, $target->version, $category, $hint);
	}
}
