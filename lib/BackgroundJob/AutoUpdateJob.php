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

use OCA\Versioniq\Service\Audit\AuditNote;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
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
		// Every audit row the run writes, by whichever installer, reads `job`.
		AuditOrigin::during(AuditOrigin::JOB, fn () => $this->runUpdates());
	}

	/**
	 * The run itself, under the job's audit origin.
	 *
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-every-audit-row-says-where-it-came-from
	 */
	private function runUpdates(): void {
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
				$this->processApp($appId, $policy);
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

	/**
	 * Plans the app afresh and installs the plan's next version: the newest
	 * in-policy version old enough, an admin's exception, or a security fix
	 * let through before the minimum age (which the audit row and the
	 * notification then say).
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	private function processApp(string $appId, Policy $policy): void {
		if ($this->isSkipped($appId)) {
			return;
		}

		$installedVersion = $this->installedVersionOf($appId);
		$entries = $installedVersion === null ? null : $this->availableEntries($appId);
		if ($installedVersion === null || $entries === null) {
			return;
		}

		$plan = $this->install->planner->plan(
			$appId,
			$installedVersion,
			$entries,
			$policy->level,
			$policy->minAgeDays ?? $this->stores->settingsStore->getMinAgeDays(),
			\DateTimeImmutable::createFromInterface($this->time->getDateTime('now', new \DateTimeZone('UTC')))
		);
		$candidate = $plan['next'];
		if ($candidate === null || $this->stores->attemptLedger->hasAttempted($appId, $candidate)) {
			// Never-retry — see "Failed attempt is not retried".
			return;
		}

		$this->attemptInstall(new InstallTarget($appId, $candidate, null), $installedVersion, self::bypassAdvisories($plan['securityBypass'], $candidate));
	}

	/**
	 * The advisories of a security fix the plan lets through before the
	 * minimum age, when that fix is the version to install; [] otherwise.
	 *
	 * @param array{version: string, advisories: list<string>, reason: string}|null $bypass
	 * @return list<string>
	 */
	private static function bypassAdvisories(?array $bypass, string $candidate): array {
		if ($bypass === null || $bypass['version'] !== $candidate || $bypass['reason'] !== AutoUpdatePlanner::REASON_SECURITY_FIX) {
			return [];
		}

		return $bypass['advisories'];
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
	 * The versions the app's source lists, with their release time when the
	 * source gives one, or null when it reported an error.
	 *
	 * @return list<array{version: string, releasedAt?: ?string}>|null
	 */
	private function availableEntries(string $appId): ?array {
		$result = $this->install->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return null;
		}

		$entries = [];
		foreach ($result['availableVersions'] ?? [] as $entry) {
			$released = $entry['releasedAt'] ?? null;
			$entries[] = ['version' => (string)$entry['version'], 'releasedAt' => is_string($released) ? $released : null];
		}

		return $entries;
	}

	/**
	 * @param list<string> $securityAdvisories The advisories of a security fix installed before the minimum age
	 */
	private function attemptInstall(InstallTarget $target, string $installedVersion, array $securityAdvisories = []): void {
		$request = new InstallRequest(
			$target,
			new InstallOptions(false, false, false, false),
			new PinChoice(null, false),
		);
		$installResult = $securityAdvisories === []
			? $this->install->installerService->installAppVersion($request)
			: AuditNote::during(
				'security fix, installed before the minimum age: ' . implode(', ', $securityAdvisories),
				fn () => $this->install->installerService->installAppVersion($request)
			);

		$at = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		$statusCode = $installResult['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
		$payload = $installResult['payload'] ?? [];

		if ($statusCode >= Http::STATUS_OK && $statusCode < Http::STATUS_MULTIPLE_CHOICES) {
			$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedger::OUTCOME_SUCCESS, $at);
			$toVersion = is_string($payload['installedVersion'] ?? null) && $payload['installedVersion'] !== ''
				? $payload['installedVersion']
				: $target->version;
			$this->notifier->notifySuccess($target->appId, $installedVersion, $toVersion, $securityAdvisories);

			return;
		}

		$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedger::OUTCOME_FAILURE, $at);
		$category = is_string($payload['category'] ?? null) ? $payload['category'] : 'unknown';
		$hint = is_string($payload['hint'] ?? null) ? $payload['hint'] : '';
		$this->notifier->notifyFailure($target->appId, $target->version, $category, $hint);
	}
}
