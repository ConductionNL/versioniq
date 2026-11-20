<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use Psr\Log\LoggerInterface;

/**
 * Plans an automatic-update run before anything installs
 * (autoupdate-run-batching D1): for every app with a policy it does what the
 * job used to do up to the candidate, and records why an app will not
 * install. It makes the same source calls the job made, and no others: a
 * pinned or unmanageable app is not looked up, and an app that is not
 * installed is not asked for releases.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdatePlanner {
	public function __construct(
		private AutoUpdateStores $stores,
		private AutoUpdateInstall $install,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The plan for a run: one member per app with a policy other than `none`.
	 * Security fixes the patch policy handles at the next window run first
	 * (autoupdate-security-first D4), then every other member in policy
	 * order. An app that fails to plan is logged and left out, so one broken
	 * app never blocks the rest. Members of an update group then move
	 * together, in the group's order, or wait together (D3), and the per-run
	 * and weekly caps and the waiting list decide the run order and what
	 * waits (D4, D5).
	 *
	 * @param array{rows: array<string, mixed>, fresh: bool} $advisories The run's advisory snapshot.
	 * @param PatchPolicy|null $patchPolicy The patch policy; null reads as none.
	 * @param array<string, string>|null $levels The level per app; null reads the stored policies.
	 * @param \DateTimeImmutable|null $now The run's time; now when null.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @spec openspec/changes/releases-minimum-age
	 */
	public function plan(array $advisories = ['rows' => [], 'fresh' => false], ?PatchPolicy $patchPolicy = null, ?array $levels = null, ?\DateTimeImmutable $now = null): AutoUpdatePlan {
		return $this->planRun(new PlanRun($advisories['rows'], $advisories['fresh'], $patchPolicy ?? PatchPolicy::none()), $levels, $now);
	}

	/**
	 * The app's own minimum age, or null when it follows the instance's: read
	 * from its stored policy, and never in file mode, where the stores are not
	 * the source (autoupdate-default-and-config-file D4).
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	private function minAgeDaysOf(string $appId): ?int {
		if ($this->stores->policySource?->filePath() !== null) {
			return null;
		}

		return $this->stores->policyStore->get($appId)?->minAgeDays;
	}

	/**
	 * The plan of one run: its advisory snapshot, patch policy and the kinds the schedule lets run tonight.
	 *
	 * @param PlanRun $run The run.
	 * @param array<string, string>|null $levels The level per app; null reads the stored policies.
	 * @param \DateTimeImmutable|null $now The run's time; now when null.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md
	 */
	public function planRun(PlanRun $run, ?array $levels = null, ?\DateTimeImmutable $now = null): AutoUpdatePlan {
		// Version ages are measured at the run's time (releases-minimum-age D2).
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$instanceMinAge = $this->stores->settingsStore->getMinAgeDays();
		$planned = [];
		foreach ($levels ?? PolicyStore::levelsOf($this->stores->policyStore->all()) as $appId => $level) {
			if ($level !== Policy::LEVEL_NONE) {
				$planned[] = $this->planIsolated(new PlanTarget((string)$appId, $level, $this->minAgeDaysOf((string)$appId) ?? $instanceMinAge, $now), $run);
			}
		}

		$limiter = new AutoUpdateLimiter($this->stores->settingsStore, $this->stores->attemptLedger);
		$grouped = AutoUpdateGroups::apply(array_values(array_filter($planned)), $this->stores->groupStore->all());
		$members = self::firstThenLater($grouped);

		return new AutoUpdatePlan($limiter->apply($members, $now ?? new \DateTimeImmutable()), $run->holds());
	}

	/**
	 * The members, those that run first ahead of the rest, each group in policy order.
	 *
	 * @param list<array{member: AutoUpdateMember, first: bool}> $planned
	 * @return list<AutoUpdateMember>
	 */
	private static function firstThenLater(array $planned): array {
		$first = array_filter($planned, static fn (array $place): bool => $place['first']);
		$later = array_filter($planned, static fn (array $place): bool => !$place['first']);

		return array_merge(array_column($first, 'member'), array_column($later, 'member'));
	}

	/**
	 * Plan one app; a failure is logged and leaves the app out of the plan.
	 *
	 * @return array{member: AutoUpdateMember, first: bool}|null
	 */
	private function planIsolated(PlanTarget $target, PlanRun $run): ?array {
		try {
			return $this->planApp($target, $run);
		} catch (\Throwable $error) {
			// Per-app isolation — see "the job MUST proceed to the next app
			// after any failure".
			$this->logger->warning('AutoUpdateJob: failed to process an app', [
				'appId' => $target->appId,
				'message' => $error->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * One app's place in the plan, or null when it has no place: it is not
	 * manageable here, or not installed.
	 *
	 * @return array{member: AutoUpdateMember, first: bool}|null
	 */
	private function planApp(PlanTarget $target, PlanRun $run): ?array {
		$subject = $this->subjectOf($target);
		if ($subject instanceof PlanSubject) {
			return $this->planSecurityFirst($subject, $run);
		}

		return $subject === null ? null : ['member' => $subject, 'first' => false];
	}

	/**
	 * The app as the planner sees it, a member when its place is already
	 * decided (pinned, or its source failed), or null when it has no place.
	 */
	private function subjectOf(PlanTarget $target): PlanSubject|AutoUpdateMember|null {
		if ($this->stores->pinStore->get($target->appId) !== null) {
			return new AutoUpdateMember($target->appId, null, null, AutoUpdateMember::REASON_PINNED);
		}

		$installedVersion = $this->install->installerService->isManageableApp($target->appId) ? $this->installedVersionOf($target->appId) : null;
		if ($installedVersion === null) {
			return null;
		}

		$entries = $this->availableEntries($target->appId);
		if ($entries === null) {
			return new AutoUpdateMember($target->appId, $installedVersion, null, AutoUpdateMember::REASON_SOURCE_ERROR);
		}

		return $this->subjectWithAges($target, $installedVersion, $entries);
	}

	/**
	 * The app as the planner sees it, with the versions younger than the
	 * minimum age set aside (releases-minimum-age D3): a regular update only
	 * chooses among the eligible ones, so the newest old-enough version is
	 * taken while a newer one waits. A security fix the age planner lets
	 * through early is eligible, and carries its advisories (D6).
	 *
	 * @param list<array{version: string, releasedAt: ?string}> $entries The source's versions.
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	private function subjectWithAges(PlanTarget $target, string $installedVersion, array $entries): PlanSubject {
		$available = array_column($entries, 'version');
		if ($target->minAgeDays <= 0 || $this->install->planner === null) {
			return new PlanSubject($target->appId, $target->level, $installedVersion, $available);
		}

		$agePlan = $this->install->planner->plan(
			$target->appId,
			$installedVersion,
			$entries,
			$target->level,
			$target->minAgeDays,
			$target->now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
		);
		$bypass = $agePlan['securityBypass'];
		$advisories = ($bypass !== null && $bypass['reason'] === MinimumAgePlanner::REASON_SECURITY_FIX) ? $bypass['advisories'] : [];

		return new PlanSubject($target->appId, $target->level, $installedVersion, $available, $agePlan['eligible'], $agePlan['bypassed'], $advisories);
	}

	/**
	 * The member for a managed, unpinned, installed app: a security fix the
	 * patch policy handles at the next window runs first, one it sets to by
	 * hand is held, a scheduled one installs with the other updates, and
	 * every other app takes the highest version within its level among the
	 * kinds the patch policy does not set to by hand.
	 *
	 * @return array{member: AutoUpdateMember, first: bool}
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	private function planSecurityFirst(PlanSubject $app, PlanRun $run): array {
		$fix = $this->securityFix($app, $run);
		if ($fix === null) {
			// Nothing a security fix can do: a `security` app is done, any
			// other level updates as usual.
			return ['member' => $this->withoutFix($app, $run), 'first' => false];
		}

		$handling = $run->patchPolicy->securityHandling(PatchPolicy::highestSeverity(self::advisoriesOf($run->rowOf($app->appId))));
		if ($handling === PatchPolicy::HANDLING_MANUAL) {
			$run->hold($app->appId, SecurityHoldStore::REASON_MANUAL);

			return ['member' => new AutoUpdateMember($app->appId, $app->installedVersion, $fix, AutoUpdateMember::REASON_HELD), 'first' => false];
		}

		if ($handling === PatchPolicy::HANDLING_NEXT_WINDOW) {
			// Not held by a notice period either (autoupdate-proposed-updates D5).
			return ['member' => $this->memberFor($app, $fix)->asNextWindowFix(), 'first' => true];
		}

		// `scheduled`: the fix installs with the other updates; any other
		// level takes its usual update, which the fix is within.
		$member = $app->level === Policy::LEVEL_SECURITY ? $this->memberFor($app, $fix) : $this->regularMember($app, $run);

		return ['member' => $member, 'first' => false];
	}

	/**
	 * The advisories listed on a snapshot row.
	 *
	 * @param array<string, mixed>|null $row
	 * @return list<mixed>
	 */
	private static function advisoriesOf(?array $row): array {
		/** @var mixed $listed */
		$listed = $row['advisories'] ?? null;

		return is_array($listed) ? array_values($listed) : [];
	}

	/**
	 * The member when no security fix applies: a `security` app has no
	 * candidate, any other level updates as usual.
	 */
	private function withoutFix(PlanSubject $app, PlanRun $run): AutoUpdateMember {
		if ($app->level === Policy::LEVEL_SECURITY) {
			return new AutoUpdateMember($app->appId, $app->installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE);
		}

		return $this->regularMember($app, $run);
	}

	/**
	 * The highest version within the app's level among the kinds the patch
	 * policy does not set to by hand and the schedule lets run tonight, or why
	 * there is none.
	 */
	private function regularMember(PlanSubject $app, PlanRun $run): AutoUpdateMember {
		$patchPolicy = $run->patchPolicy;
		$installedVersion = $app->installedVersion;
		$allowed = array_values(array_filter(
			$app->installable(),
			static fn (string $version): bool => $patchPolicy->regularHandling(PatchPolicy::kindOf($installedVersion, $version)) !== PatchPolicy::HANDLING_MANUAL
				&& $run->isDue($installedVersion, $version),
		));
		$candidate = $this->install->candidateSelector->select($installedVersion, $allowed, $app->level, $this->skippedOf($app->appId));
		if ($candidate === null) {
			return new AutoUpdateMember($app->appId, $installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE);
		}

		return $this->memberFor($app, $candidate);
	}

	/**
	 * A member that installs the version, unless that exact version was
	 * attempted before (never-retry — see "Failed attempt is not retried").
	 */
	private function memberFor(PlanSubject $app, string $version): AutoUpdateMember {
		if ($this->stores->attemptLedger->hasAttempted($app->appId, $version)) {
			return new AutoUpdateMember($app->appId, $app->installedVersion, $version, AutoUpdateMember::REASON_BLOCKED);
		}

		$advisories = ($app->bypassed !== null && $version === $app->bypassed) ? $app->bypassAdvisories : [];

		return new AutoUpdateMember($app->appId, $app->installedVersion, $version, null, $advisories);
	}

	/**
	 * The security fix this app's level can install now: for the `security`
	 * level the fix the advisory check recommends (see SecurityCandidate),
	 * for any other level that same fix when the level's own selection
	 * includes it. Null when the app is not affected or the fix is out of
	 * reach; a `security` app records why.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	private function securityFix(PlanSubject $app, PlanRun $run): ?string {
		if ($app->level !== Policy::LEVEL_SECURITY) {
			return $this->fixWithinLevel($app, $run);
		}

		$decision = SecurityCandidate::decide($app, $run);
		if ($decision['version'] === null && $decision['reason'] !== SecurityCandidate::REASON_NOT_AFFECTED) {
			$this->logger->info('AutoUpdateJob: no security fix installed', ['appId' => $app->appId, 'reason' => $decision['reason']]);
			$run->hold($app->appId, (string)$decision['reason']);
		}

		return $decision['version'];
	}

	/**
	 * For a level other than `security`: the recommended fix when the
	 * snapshot is fresh, the app is affected, its source lists the fix and
	 * the level's own selection includes it; else null.
	 */
	private function fixWithinLevel(PlanSubject $app, PlanRun $run): ?string {
		$recommended = SecurityCandidate::listedFix($app, $run);
		if ($recommended === null) {
			return null;
		}

		return $this->install->candidateSelector->select($app->installedVersion, [$recommended], $app->level, $this->skippedOf($app->appId)) === $recommended ? $recommended : null;
	}

	/**
	 * The versions an admin skipped for the app: the job never chooses them
	 * and the next qualifying version is chosen instead (pinning-ranges-and-skips D2).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
	 */
	private function skippedOf(string $appId): array {
		return $this->stores->skipList?->versions($appId) ?? [];
	}

	/**
	 * The installed version, or null when the app is not installed.
	 */
	private function installedVersionOf(string $appId): ?string {
		try {
			$installed = $this->install->appManager->getAppVersion($appId, false);
		} catch (\Throwable) {
			return null;
		}

		return $installed !== '' ? $installed : null;
	}

	/**
	 * The versions the app's source lists on its release channel, with their
	 * release times when the source gives one, or null when the source failed.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-nightly-job-takes-only-versions-on-the-apps-channel
	 *
	 * @return list<array{version: string, releasedAt: ?string}>|null
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	private function availableEntries(string $appId): ?array {
		$result = $this->install->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return null;
		}

		$entries = [];
		foreach ($result['availableVersions'] ?? [] as $entry) {
			// Only versions on the app's release channel are candidates; an entry
			// without the stamp predates channels and stays one.
			if (($entry['onChannel'] ?? true) === false) {
				continue;
			}
			$released = $entry['releasedAt'] ?? null;
			$entries[] = ['version' => (string)$entry['version'], 'releasedAt' => is_string($released) ? $released : null];
		}

		return $entries;
	}
}
