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

use OCA\Versioniq\Kernel\Contracts\AttemptLedgerInterface;
use OCA\Versioniq\Kernel\Contracts\AutoUpdateNotifierInterface;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlan;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\Checks\CheckRunner;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\AutoUpdate\PlanRun;
use OCA\Versioniq\Service\AutoUpdate\SecurityCandidate;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Policy\PatchPolicy;
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
 * audit trail) as every other install in this app. When an update check
 * matches the app, a dry run with those checks runs first and the version is
 * installed only when it and every check pass; see "The nightly job installs
 * only after the attached checks pass". Every attempt is recorded
 * in the {@see AttemptLedgerInterface} (never-retry) and reported via
 * {@see AutoUpdateNotifierInterface} (success or failure — a no-op app is never
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
		private AutoUpdateNotifierInterface $notifier,
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

		// The weekday is the one the window opened on (autoupdate-schedules, D3);
		// with no kind due tonight, no source is asked at all (D4).
		$this->runDue($this->stores->settingsStore->getSchedule()->dueKinds(AutoUpdateWindow::openedOn($window, $now)));
	}

	/**
	 * Plan and install tonight's run for the kinds the schedule lets run; nothing when none is due.
	 *
	 * @param list<string> $dueKinds
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	private function runDue(array $dueKinds): void {
		if ($dueKinds === []) {
			return;
		}

		// Plan first, then install (autoupdate-run-batching D1). The advisory
		// snapshot and the patch policy are read once per run
		// (autoupdate-security-first D2, D3); no stored policy reads as
		// today's behaviour, and security fixes are planned first (D4).
		$advisories = $this->advisorySnapshot();
		$run = new PlanRun($advisories['rows'], $advisories['fresh'], $this->stores->security->patchPolicyStore->read() ?? PatchPolicy::none());
		$plan = (new AutoUpdatePlanner($this->stores, $this->install, $this->logger))->planRun($run->withDueKinds($dueKinds));
		$this->installPlanned($plan);
		$this->stores->security->securityHolds->replace($plan->securityHolds);
	}

	/**
	 * The last advisory snapshot, and whether it is fresh enough to decide
	 * security installs (autoupdate-security-first D2).
	 *
	 * @return array{rows: array<string, mixed>, fresh: bool}
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	private function advisorySnapshot(): array {
		$snapshot = $this->stores->security->advisoryResults->read();
		$checkedAt = $snapshot['checkedAt'] ?? null;

		return [
			'rows' => is_array($snapshot['advisories'] ?? null) ? $snapshot['advisories'] : [],
			'fresh' => SecurityCandidate::isFresh(
				is_int($checkedAt) ? $checkedAt : null,
				$this->time->getTime(),
				$this->stores->security->advisorySettings->getIntervalSeconds()
			),
		];
	}

	/**
	 * Install every member of the plan that will install, one app at a time.
	 */
	private function installPlanned(AutoUpdatePlan $plan): void {
		foreach ($plan->installable() as $member) {
			try {
				$this->attemptInstall(
					new InstallTarget($member->appId, (string)$member->candidate, null),
					(string)$member->installedVersion
				);
			} catch (\Throwable $error) {
				// Per-app isolation — see "the job MUST proceed to the next app
				// after any failure".
				$this->logger->warning('AutoUpdateJob: failed to process an app', [
					'appId' => $member->appId,
					'message' => $error->getMessage(),
				]);
			}
		}
	}

	/**
	 * Installs the candidate, after a dry run with its checks when an update
	 * check matches the app; a failed dry run or check blocks the version like
	 * a failed install.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/safety-update-checks/specs/auto-update-policies/spec.md#requirement-the-nightly-job-installs-only-after-the-attached-checks-pass
	 */
	private function attemptInstall(InstallTarget $target, string $installedVersion): void {
		$at = $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
		$blocked = $this->install->checkRunner->matches($target->appId) ? $this->gateHint($target) : null;
		if ($blocked !== null) {
			$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedgerInterface::OUTCOME_FAILURE, $at);
			$this->notifier->notifyFailure($target->appId, $target->version, FailureClassifier::CATEGORY_CHECKS_FAILED, $blocked);

			return;
		}

		$installResult = $this->install->installerService->installAppVersion(new InstallRequest(
			$target,
			new InstallOptions(false, false, false, false, CheckRunner::CONTEXT_CLI),
			new PinChoice(null, false),
		));

		$statusCode = $installResult['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
		$payload = $installResult['payload'] ?? [];

		if ($statusCode >= Http::STATUS_OK && $statusCode < Http::STATUS_MULTIPLE_CHOICES) {
			$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedgerInterface::OUTCOME_SUCCESS, $at);
			$this->notifier->notifySuccess($target->appId, $installedVersion, self::versionInstalled($payload, $target));

			return;
		}

		$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedgerInterface::OUTCOME_FAILURE, $at);
		$this->notifyFailure($target, $payload);
	}

	/**
	 * The version the installer reports it installed, else the target version.
	 *
	 * @param array<array-key, mixed> $payload The installer's outcome payload.
	 */
	private static function versionInstalled(array $payload, InstallTarget $target): string {
		/** @var mixed $reported */
		$reported = $payload['installedVersion'] ?? null;

		return is_string($reported) && $reported !== '' ? $reported : $target->version;
	}

	/**
	 * Tells the admins an automatic install failed, with the failure category
	 * and hint the installer gave ('unknown' and '' when it gave none).
	 *
	 * @param array<array-key, mixed> $payload The installer's outcome payload.
	 */
	private function notifyFailure(InstallTarget $target, array $payload): void {
		$category = is_string($payload['category'] ?? null) ? $payload['category'] : 'unknown';
		$hint = is_string($payload['hint'] ?? null) ? $payload['hint'] : '';
		$this->notifier->notifyFailure($target->appId, $target->version, $category, $hint);
	}

	/**
	 * Runs a dry run of the candidate with every matching check, as from the
	 * command line, and returns the hint naming what failed, or null when the
	 * dry run and every check passed.
	 *
	 * @spec openspec/changes/safety-update-checks/specs/auto-update-policies/spec.md#requirement-the-nightly-job-installs-only-after-the-attached-checks-pass
	 */
	private function gateHint(InstallTarget $target): ?string {
		$dryRun = $this->install->installerService->installAppVersion(new InstallRequest(
			$target,
			new InstallOptions(false, true, false, false, CheckRunner::CONTEXT_CLI),
			new PinChoice(null, false),
		));
		$statusCode = $dryRun['statusCode'];
		$payload = $dryRun['payload'];
		if ($statusCode < Http::STATUS_OK || $statusCode >= Http::STATUS_MULTIPLE_CHOICES) {
			$message = is_string($payload['message'] ?? null) ? $payload['message'] : 'The dry run failed.';

			return $this->install->failureClassifier->dryRunFailedHint($message);
		}

		$failure = CheckRunner::firstFailure(is_array($payload['checks'] ?? null) ? $payload['checks'] : []);
		if ($failure === null) {
			return null;
		}
		$this->logger->info('AutoUpdateJob: an update check held back a version', [
			'appId' => $target->appId,
			'version' => $target->version,
			'check' => $failure['name'],
		]);

		return $this->install->failureClassifier->checksFailedHint($failure['name'], $failure['detail']);
	}
}
