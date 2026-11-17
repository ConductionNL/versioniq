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
use OCA\Versioniq\Service\AutoUpdate\SecurityCandidate;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyFileContents;
use OCA\Versioniq\Service\Policy\PolicyFileException;
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
		$file = $this->fileContents();
		if ($file === false) {
			return;
		}

		if (!$this->isEnabled($file)) {
			// Kill switch off — policies remain stored but inert; see "Global
			// kill switch and window" ("Kill switch inert-but-stored").
			return;
		}

		$zoneName = $this->stores->settingsStore->getTimeZoneName();
		$now = $this->time->getDateTime('now', new \DateTimeZone($zoneName !== '' ? $zoneName : 'UTC'));
		$window = $file?->window ?? $this->stores->settingsStore->getWindow();
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

		// Plan first, then install (autoupdate-run-batching D1). The advisory
		// snapshot and the patch policy are read once per run
		// (autoupdate-security-first D2, D3); no stored policy reads as
		// today's behaviour, and security fixes are planned first (D4).
		// The levels come from the policy file in file mode, else the stores,
		// with every other enabled app at the instance default
		// (autoupdate-default-and-config-file).
		$plan = (new AutoUpdatePlanner($this->stores, $this->install, $this->logger))->plan(
			$this->advisorySnapshot(),
			$this->stores->security->patchPolicyStore->read() ?? PatchPolicy::none(),
			$this->levelsByApp($file)
		);
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
	 * In file mode (design D4) the policy file, read afresh; null when the
	 * stores are the source; false when the file cannot be used, which makes
	 * the run do nothing rather than fall back to the stores.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	private function fileContents(): PolicyFileContents|null|false {
		$source = $this->stores->policySource;
		$path = $source?->filePath();
		if ($source === null || $path === null) {
			return null;
		}

		try {
			return $source->current();
		} catch (PolicyFileException $error) {
			$this->logger->error('AutoUpdateJob: the policy file cannot be used, so nothing is updated', [
				'file' => $path,
				'errors' => $error->errors,
			]);
			return false;
		}
	}

	/** The kill switch: the file's in file mode (off when it does not say), else the stored one. */
	private function isEnabled(?PolicyFileContents $file): bool {
		if ($file !== null) {
			return $file->enabled ?? false;
		}

		return $this->stores->settingsStore->isEnabled();
	}

	/**
	 * The level to apply per app: every policy (stored, or the file's), then
	 * every enabled app without one at the instance default. With the default
	 * at none this is exactly the policies, as before; see "Instance default level".
	 *
	 * @return array<string, string>
	 */
	private function levelsByApp(?PolicyFileContents $file): array {
		$levels = $file?->policies ?? $this->storedLevels();
		$default = $file !== null ? ($file->defaultLevel ?? Policy::LEVEL_NONE) : $this->stores->settingsStore->getDefaultLevel();
		if (!Policy::isValidLevel($default) || $default === Policy::LEVEL_NONE) {
			return $levels;
		}

		foreach ($this->install->appManager->getEnabledApps() as $appId) {
			$levels[$appId] ??= $default;
		}

		return $levels;
	}

	/**
	 * @return array<string, string>
	 */
	private function storedLevels(): array {
		$levels = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			$levels[$appId] = $policy->level;
		}

		return $levels;
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
}
