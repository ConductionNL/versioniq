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
use OCA\Versioniq\Service\Audit\AuditNote;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateMember;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlan;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanner;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\Checks\CheckRunner;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\AutoUpdate\PlanRun;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCA\Versioniq\Service\AutoUpdate\SecurityCandidate;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyFileContents;
use OCA\Versioniq\Service\Policy\PolicyFileException;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
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

	/** Whether this sweep already took its `before_auto_update` snapshot. */
	private bool $snapshotTaken = false;

	public function __construct(
		JobRuntime $runtime,
		private AutoUpdateStores $stores,
		private AutoUpdateInstall $install,
		private AutoUpdateNotifierInterface $notifier,
		private ?SnapshotTaker $snapshotTaker = null,
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
		$this->snapshotTaken = false;

		// The weekday is the one the window opened on (autoupdate-schedules, D3);
		// with no kind due tonight, no source is asked at all (D4).
		$this->runDue($this->stores->settingsStore->getSchedule()->dueKinds(AutoUpdateWindow::openedOn($window, $now)), $file, $now);
	}

	/**
	 * Plan and install tonight's run for the kinds the schedule lets run; nothing when none is due.
	 *
	 * @param list<string> $dueKinds
	 * @param PolicyFileContents|null $file The policy file in file mode, else null.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	private function runDue(array $dueKinds, ?PolicyFileContents $file, \DateTimeInterface $now): void {
		if ($dueKinds === []) {
			return;
		}

		// Plan first, then install (autoupdate-run-batching D1). The advisory
		// snapshot and the patch policy are read once per run
		// (autoupdate-security-first D2, D3); no stored policy reads as
		// today's behaviour, and security fixes are planned first (D4).
		// The levels come from the policy file in file mode, else the stores,
		// with every other enabled app at the instance default
		// (autoupdate-default-and-config-file).
		$advisories = $this->advisorySnapshot();
		$run = new PlanRun($advisories['rows'], $advisories['fresh'], $this->stores->security->patchPolicyStore->read() ?? PatchPolicy::none());
		$plan = (new AutoUpdatePlanner($this->stores, $this->install, $this->logger))->planRun(
			$run->withDueKinds($dueKinds),
			$this->levelsByApp($file),
			// Version ages are measured at the run's time (releases-minimum-age D2).
			\DateTimeImmutable::createFromInterface($now)
		);
		$this->installPlanned($plan, $now);
		$this->stores->security->securityHolds->replace($plan->securityHolds);
	}

	/**
	 * Whether an admin's decision or the notice period holds this version
	 * back, checked in the order of design D6: a declined version is skipped;
	 * with a notice period the version must be the stored proposal and its
	 * planned time must have come, unless it is a `next_window` security fix;
	 * an app that requires approval installs only the exact version an admin
	 * approved. Without notice, approval or
	 * declines nothing is held back.
	 *
	 * @spec openspec/changes/autoupdate-proposed-updates/specs/auto-update-policies/spec.md
	 */
	private function isHeldBack(AutoUpdateMember $member, \DateTimeInterface $now): bool {
		$appId = $member->appId;
		$candidate = (string)$member->candidate;
		$proposals = $this->stores->proposalStore;
		if ($proposals === null) {
			return false;
		}
		$policy = $this->stores->policyStore->get($appId);
		if ($proposals->declineOf($appId, $candidate) !== null) {
			return true;
		}
		// A security fix the patch policy installs at the next window skips the
		// notice period; a decline and approval still apply (D5).
		if (!$member->nextWindowFix && $this->stores->settingsStore->getNoticeDays() > 0 && !$this->noticeHasPassed($proposals->proposalFor($appId), $candidate, $now)) {
			return true;
		}

		return $policy !== null && $policy->requireApproval && !$proposals->isApproved($appId, $candidate);
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
	 * Members of an update group install in the group's order; at the first
	 * failure the group stops and the members after it are not attempted, so
	 * they are not written to the ledger and plan again next run (D3). A
	 * group's installs are audited as "Update group {name}" and reported in
	 * one notification per group instead of one per app.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	private function installPlanned(AutoUpdatePlan $plan, \DateTimeInterface $now): void {
		/** @var array<string, list<array{appId: string, version: string, outcome: string}>> $groupOutcomes */
		$groupOutcomes = [];
		/** @var array<string, true> $stopped */
		$stopped = [];
		foreach ($plan->installable() as $member) {
			$outcome = $this->runMember($member, $stopped, $now);
			if ($outcome !== null) {
				$groupOutcomes[(string)$member->group][] = $outcome;
			}
		}

		foreach ($groupOutcomes as $group => $outcomes) {
			$this->notifier->notifyGroup($group, $outcomes);
		}
	}

	/**
	 * Installs one member; for a group member, the outcome its group reports.
	 * A group stops at its first failure: the members after it are not attempted.
	 *
	 * @param array<string, true> $stopped The groups that stopped, updated here.
	 *
	 * @return array{appId: string, version: string, outcome: string}|null Null for an app in no group.
	 */
	private function runMember(AutoUpdateMember $member, array &$stopped, \DateTimeInterface $now): ?array {
		$group = $member->group;
		$heldBack = $this->isHeldBack($member, $now);
		if ($group === null) {
			if (!$heldBack) {
				$this->installMember($member);
			}
			return null;
		}

		// A held-back member stops its group like a failure, so the group keeps
		// its order; nothing is written to the ledger and it plans again next run.
		if ($heldBack) {
			$stopped[$group] = true;
		}
		if (isset($stopped[$group])) {
			return self::groupOutcome($member, AutoUpdateNotifier::OUTCOME_NOT_ATTEMPTED);
		}

		$installed = AuditNote::during('Update group ' . $group, fn (): bool => $this->installMember($member));
		if (!$installed) {
			$stopped[$group] = true;
		}

		return self::groupOutcome($member, $installed ? AutoUpdateNotifier::OUTCOME_INSTALLED : AutoUpdateNotifier::OUTCOME_FAILED);
	}

	/**
	 * One line of a group's outcome.
	 *
	 * @return array{appId: string, version: string, outcome: string}
	 */
	private static function groupOutcome(AutoUpdateMember $member, string $outcome): array {
		return ['appId' => $member->appId, 'version' => (string)$member->candidate, 'outcome' => $outcome];
	}

	/**
	 * Installs one member; whether it installed. An error is logged and
	 * counts as a failure, and the run moves on.
	 */
	private function installMember(AutoUpdateMember $member): bool {
		try {
			return $this->attemptInstall(
				new InstallTarget($member->appId, (string)$member->candidate, null),
				(string)$member->installedVersion,
				$member->group === null,
				$member->securityAdvisories
			);
		} catch (\Throwable $error) {
			// Per-app isolation — see "the job MUST proceed to the next app
			// after any failure".
			$this->logger->warning('AutoUpdateJob: failed to process an app', [
				'appId' => $member->appId,
				'message' => $error->getMessage(),
			]);

			return false;
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
		$levels = $file?->policies ?? PolicyStore::levelsOf($this->stores->policyStore->all());
		$default = $this->defaultLevelFor($file);
		if ($default === Policy::LEVEL_NONE) {
			return $levels;
		}

		foreach ($this->install->appManager->getEnabledApps() as $appId) {
			$levels[$appId] ??= $default;
		}

		return $levels;
	}

	/** The instance default level: the file's in file mode, else the stored one; anything invalid reads as none. */
	private function defaultLevelFor(?PolicyFileContents $file): string {
		$default = $file !== null ? ($file->defaultLevel ?? Policy::LEVEL_NONE) : $this->stores->settingsStore->getDefaultLevel();

		return Policy::isValidLevel($default) ? $default : Policy::LEVEL_NONE;
	}

	/**
	 * Installs the target and records the attempt; whether it installed.
	 * When an update check matches the app, a dry run with its checks runs
	 * first; a failed dry run or check blocks the version like a failed
	 * install. The per-app notification is sent only when $notify is set: a
	 * group member is reported in its group's notification.
	 *
	 * A security fix installed before the minimum age carries its advisories
	 * as an audit note and in the success notification (releases-minimum-age D6).
	 *
	 * @param list<string> $securityAdvisories The advisories of a security fix installed before the minimum age
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/safety-update-checks/specs/auto-update-policies/spec.md#requirement-the-nightly-job-installs-only-after-the-attached-checks-pass
	 */
	private function attemptInstall(InstallTarget $target, string $installedVersion, bool $notify = true, array $securityAdvisories = []): bool {
		$blocked = $this->install->checkRunner->matches($target->appId) ? $this->gateHint($target) : null;
		if ($blocked !== null) {
			$this->stores->attemptLedger->record($target->appId, $target->version, AttemptLedgerInterface::OUTCOME_FAILURE, $this->now());
			if ($notify) {
				$this->notifier->notifyFailure($target->appId, $target->version, FailureClassifier::CATEGORY_CHECKS_FAILED, $blocked);
			}

			return false;
		}

		[$installed, $payload] = $securityAdvisories === []
			? $this->installAndRecord($target)
			: AuditNote::during(
				'Security fix, installed before the minimum age: ' . implode(', ', $securityAdvisories),
				fn (): array => $this->installAndRecord($target)
			);
		if ($notify && $installed) {
			$this->notifier->notifySuccess($target->appId, $installedVersion, self::versionInstalled($payload, $target), $securityAdvisories);
		}
		if ($notify && !$installed) {
			$this->notifyFailure($target, $payload);
		}

		return $installed;
	}

	/**
	 * Installs the target and records the attempt in the ledger.
	 *
	 * @return array{0: bool, 1: array<array-key, mixed>} Whether it installed, and the installer's payload.
	 */
	private function installAndRecord(InstallTarget $target): array {
		$this->snapshotBeforeFirstInstall();
		$installResult = $this->install->installerService->installAppVersion(new InstallRequest(
			$target,
			new InstallOptions(false, false, false, false, checkContext: CheckRunner::CONTEXT_CLI),
			new PinChoice(null, false),
		));

		$at = $this->now();
		$statusCode = $installResult['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR;
		$payload = $installResult['payload'] ?? [];
		$installed = $statusCode >= Http::STATUS_OK && $statusCode < Http::STATUS_MULTIPLE_CHOICES;
		$outcome = $installed ? AttemptLedgerInterface::OUTCOME_SUCCESS : AttemptLedgerInterface::OUTCOME_FAILURE;
		$this->stores->attemptLedger->record($target->appId, $target->version, $outcome, $at);

		return [$installed, $payload];
	}

	/**
	 * The current time in UTC, as the ledger records it.
	 */
	private function now(): string {
		return $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
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
	 * Takes the `before_auto_update` snapshot once per sweep, right before its
	 * first install; a sweep that installs nothing takes none. A failing
	 * snapshot is logged and does not stop the sweep.
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	private function snapshotBeforeFirstInstall(): void {
		if ($this->snapshotTaken || $this->snapshotTaker === null) {
			return;
		}
		$this->snapshotTaken = true;
		try {
			$this->snapshotTaker->take('before_auto_update', 'system');
		} catch (\Throwable $error) {
			$this->logger->warning('AutoUpdateJob: the snapshot before the sweep failed', ['message' => $error->getMessage()]);
		}
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
			new InstallOptions(false, true, false, false, checkContext: CheckRunner::CONTEXT_CLI),
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
