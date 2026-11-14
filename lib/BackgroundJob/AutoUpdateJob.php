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

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\SecurityCandidate;
use OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore;
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

		// The advisory snapshot and the patch policy are read once per run
		// (autoupdate-security-first D2, D3); no stored policy reads as
		// today's behaviour.
		$advisories = $this->advisorySnapshot();
		$patchPolicy = $this->stores->patchPolicyStore->read() ?? PatchPolicy::none();
		$holds = [];
		$later = [];

		// First pass: security fixes go before any other update (D4).
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			try {
				$app = $this->securityPass($appId, $policy->level, $advisories, $patchPolicy, $holds);
				if ($app !== null) {
					$later[$appId] = $app;
				}
			} catch (\Throwable $error) {
				// Per-app isolation — see "the job MUST proceed to the next app
				// after any failure".
				$this->logFailure($appId, $error);
			}
		}

		// Second pass: every remaining app as before, without kinds the patch
		// policy sets to by hand.
		foreach ($later as $appId => $app) {
			try {
				$this->regularPass($appId, $app, $patchPolicy);
			} catch (\Throwable $error) {
				$this->logFailure($appId, $error);
			}
		}

		$this->stores->securityHolds->replace($holds);
	}

	private function logFailure(string $appId, \Throwable $error): void {
		$this->logger->warning('AutoUpdateJob: failed to process an app', [
			'appId' => $appId,
			'message' => $error->getMessage(),
		]);
	}

	/**
	 * The first pass for one app: installs a security fix whose severity the
	 * patch policy handles at the next window, leaves an app whose fix is set
	 * to by hand alone, and returns what the second pass needs for the rest.
	 *
	 * @param array{rows: array<string, mixed>, fresh: bool} $advisories The run's advisory snapshot.
	 * @param array<string, string> $holds Why a fix was held back, by app id.
	 * @return array{level: string, installed: string, available: list<string>, fix: ?string}|null Null when the app is done for this run.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	private function securityPass(string $appId, string $level, array $advisories, PatchPolicy $patchPolicy, array &$holds): ?array {
		if ($this->isSkipped($appId)) {
			return null;
		}

		$installedVersion = $this->installedVersionOf($appId);
		$available = $installedVersion === null ? null : $this->availableVersions($appId);
		if ($installedVersion === null || $available === null) {
			return null;
		}

		$app = ['level' => $level, 'installed' => $installedVersion, 'available' => $available, 'fix' => null];
		/** @var mixed $stored */
		$stored = $advisories['rows'][$appId] ?? null;
		/** @var array<string, mixed>|null $row */
		$row = is_array($stored) ? $stored : null;
		$fix = $this->securityFix($appId, $app, $row, $advisories['fresh'], $holds);
		if ($fix === null) {
			// Nothing a security fix can do: a `security` app is done, any
			// other level updates as usual.
			return $level === Policy::LEVEL_SECURITY ? null : $app;
		}

		/** @var mixed $listed */
		$listed = $row['advisories'] ?? null;
		$advisoryRows = is_array($listed) ? array_values($listed) : [];
		$handling = $patchPolicy->securityHandling(PatchPolicy::highestSeverity($advisoryRows));
		if ($handling === PatchPolicy::HANDLING_MANUAL) {
			$holds[$appId] = SecurityHoldStore::REASON_MANUAL;

			return null;
		}

		if ($handling === PatchPolicy::HANDLING_NEXT_WINDOW) {
			$this->installIfNew($appId, $fix, $installedVersion);

			return null;
		}

		// `scheduled`: the fix installs with the other updates.
		$app['fix'] = $level === Policy::LEVEL_SECURITY ? $fix : null;

		return $app;
	}

	/**
	 * The second pass for one app: a scheduled security fix for a `security`
	 * app, else the highest version within the app's level among the kinds
	 * the patch policy does not set to by hand.
	 *
	 * @param array{level: string, installed: string, available: list<string>, fix: ?string} $app
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	private function regularPass(string $appId, array $app, PatchPolicy $patchPolicy): void {
		if ($app['fix'] !== null) {
			$this->installIfNew($appId, $app['fix'], $app['installed']);

			return;
		}

		if ($app['level'] === Policy::LEVEL_SECURITY) {
			return;
		}

		$installed = $app['installed'];
		$allowed = array_values(array_filter(
			$app['available'],
			static fn (string $version): bool => $patchPolicy->regularHandling(PatchPolicy::kindOf($installed, $version)) !== PatchPolicy::HANDLING_MANUAL,
		));
		$candidate = $this->install->candidateSelector->select($installed, $allowed, $app['level']);
		if ($candidate !== null) {
			$this->installIfNew($appId, $candidate, $installed);
		}
	}

	/**
	 * Installs a version unless that exact version was attempted before
	 * (never-retry — see "Failed attempt is not retried").
	 */
	private function installIfNew(string $appId, string $version, string $installedVersion): void {
		if ($this->stores->attemptLedger->hasAttempted($appId, $version)) {
			return;
		}

		$this->attemptInstall(new InstallTarget($appId, $version, null), $installedVersion);
	}

	/**
	 * The security fix this app's level can install now: for the `security`
	 * level the fix the advisory check recommends (see SecurityCandidate),
	 * for any other level that same fix when the level's own selection
	 * includes it. Null when the app is not affected or the fix is out of
	 * reach; a `security` app records why.
	 *
	 * @param array{level: string, installed: string, available: list<string>, fix: ?string} $app
	 * @param array<string, mixed>|null $row The app's advisory row.
	 * @param array<string, string> $holds Why a fix was held back, by app id.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	private function securityFix(string $appId, array $app, ?array $row, bool $fresh, array &$holds): ?string {
		if ($app['level'] === Policy::LEVEL_SECURITY) {
			$decision = SecurityCandidate::decide($app['installed'], $row, $app['available'], $fresh);
			if ($decision['version'] === null && $decision['reason'] !== SecurityCandidate::REASON_NOT_AFFECTED) {
				$this->logger->info('AutoUpdateJob: no security fix installed', ['appId' => $appId, 'reason' => $decision['reason']]);
				$holds[$appId] = (string)$decision['reason'];
			}

			return $decision['version'];
		}

		$recommended = $row['recommendedVersion'] ?? null;
		if (!$fresh || ($row['state'] ?? null) !== AdvisoryService::STATE_VULNERABLE
			|| !is_string($recommended) || !in_array($recommended, $app['available'], true)) {
			return null;
		}

		return $this->install->candidateSelector->select($app['installed'], [$recommended], $app['level']) === $recommended ? $recommended : null;
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
		$snapshot = $this->stores->advisoryResults->read();
		$checkedAt = $snapshot['checkedAt'] ?? null;

		return [
			'rows' => is_array($snapshot['advisories'] ?? null) ? $snapshot['advisories'] : [],
			'fresh' => SecurityCandidate::isFresh(
				is_int($checkedAt) ? $checkedAt : null,
				$this->time->getTime(),
				$this->stores->advisorySettings->getIntervalSeconds()
			),
		];
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
