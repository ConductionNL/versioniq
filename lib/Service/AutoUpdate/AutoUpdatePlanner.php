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

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\Policy;
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
	 * app never blocks the rest.
	 *
	 * @param array{rows: array<string, mixed>, fresh: bool} $advisories The run's advisory snapshot.
	 * @param PatchPolicy|null $patchPolicy The patch policy; null reads as none.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	public function plan(array $advisories = ['rows' => [], 'fresh' => false], ?PatchPolicy $patchPolicy = null): AutoUpdatePlan {
		$run = new PlanRun($advisories['rows'], $advisories['fresh'], $patchPolicy ?? PatchPolicy::none());
		$first = [];
		$later = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			$planned = $this->planIsolated($appId, $policy->level, $run);
			if ($planned === null) {
				continue;
			}

			if ($planned['first']) {
				$first[] = $planned['member'];
			} else {
				$later[] = $planned['member'];
			}
		}

		return new AutoUpdatePlan(array_merge($first, $later), $run->holds());
	}

	/**
	 * Plan one app; a failure is logged and leaves the app out of the plan.
	 *
	 * @return array{member: AutoUpdateMember, first: bool}|null
	 */
	private function planIsolated(string $appId, string $level, PlanRun $run): ?array {
		try {
			return $this->planApp($appId, $level, $run);
		} catch (\Throwable $error) {
			// Per-app isolation — see "the job MUST proceed to the next app
			// after any failure".
			$this->logger->warning('AutoUpdateJob: failed to process an app', [
				'appId' => $appId,
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
	private function planApp(string $appId, string $level, PlanRun $run): ?array {
		if ($this->stores->pinStore->get($appId) !== null) {
			return ['member' => new AutoUpdateMember($appId, null, null, AutoUpdateMember::REASON_PINNED), 'first' => false];
		}

		if (!$this->install->installerService->isManageableApp($appId)) {
			return null;
		}

		$installedVersion = $this->installedVersionOf($appId);
		if ($installedVersion === null) {
			return null;
		}

		$available = $this->availableVersions($appId);
		if ($available === null) {
			return ['member' => new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_SOURCE_ERROR), 'first' => false];
		}

		return $this->planSecurityFirst(new PlanSubject($appId, $level, $installedVersion, $available), $run);
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
		$row = $run->rowOf($app->appId);
		$fix = $this->securityFix($app, $row, $run);
		if ($fix === null) {
			// Nothing a security fix can do: a `security` app is done, any
			// other level updates as usual.
			return ['member' => $this->withoutFix($app, $run->patchPolicy), 'first' => false];
		}

		/** @var mixed $listed */
		$listed = $row['advisories'] ?? null;
		$handling = $run->patchPolicy->securityHandling(PatchPolicy::highestSeverity(is_array($listed) ? array_values($listed) : []));
		if ($handling === PatchPolicy::HANDLING_MANUAL) {
			$run->hold($app->appId, SecurityHoldStore::REASON_MANUAL);

			return ['member' => new AutoUpdateMember($app->appId, $app->installedVersion, $fix, AutoUpdateMember::REASON_HELD), 'first' => false];
		}

		if ($handling === PatchPolicy::HANDLING_NEXT_WINDOW) {
			return ['member' => $this->memberFor($app, $fix), 'first' => true];
		}

		// `scheduled`: the fix installs with the other updates.
		if ($app->level === Policy::LEVEL_SECURITY) {
			return ['member' => $this->memberFor($app, $fix), 'first' => false];
		}

		return ['member' => $this->regularMember($app, $run->patchPolicy), 'first' => false];
	}

	/**
	 * The member when no security fix applies: a `security` app has no
	 * candidate, any other level updates as usual.
	 */
	private function withoutFix(PlanSubject $app, PatchPolicy $patchPolicy): AutoUpdateMember {
		if ($app->level === Policy::LEVEL_SECURITY) {
			return new AutoUpdateMember($app->appId, $app->installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE);
		}

		return $this->regularMember($app, $patchPolicy);
	}

	/**
	 * The highest version within the app's level among the kinds the patch
	 * policy does not set to by hand, or why there is none.
	 */
	private function regularMember(PlanSubject $app, PatchPolicy $patchPolicy): AutoUpdateMember {
		$installedVersion = $app->installedVersion;
		$allowed = array_values(array_filter(
			$app->available,
			static fn (string $version): bool => $patchPolicy->regularHandling(PatchPolicy::kindOf($installedVersion, $version)) !== PatchPolicy::HANDLING_MANUAL,
		));
		$candidate = $this->install->candidateSelector->select($installedVersion, $allowed, $app->level);
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

		return new AutoUpdateMember($app->appId, $app->installedVersion, $version, null);
	}

	/**
	 * The security fix this app's level can install now: for the `security`
	 * level the fix the advisory check recommends (see SecurityCandidate),
	 * for any other level that same fix when the level's own selection
	 * includes it. Null when the app is not affected or the fix is out of
	 * reach; a `security` app records why.
	 *
	 * @param array<string, mixed>|null $row The app's advisory row.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	private function securityFix(PlanSubject $app, ?array $row, PlanRun $run): ?string {
		if ($app->level !== Policy::LEVEL_SECURITY) {
			return $this->fixWithinLevel($app, $row, $run->fresh);
		}

		$decision = SecurityCandidate::decide($app->installedVersion, $row, $app->available, $run->fresh);
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
	 *
	 * @param array<string, mixed>|null $row The app's advisory row.
	 */
	private function fixWithinLevel(PlanSubject $app, ?array $row, bool $fresh): ?string {
		$recommended = $row['recommendedVersion'] ?? null;
		if (!$fresh || ($row['state'] ?? null) !== AdvisoryService::STATE_VULNERABLE
			|| !is_string($recommended) || !in_array($recommended, $app->available, true)) {
			return null;
		}

		return $this->install->candidateSelector->select($app->installedVersion, [$recommended], $app->level) === $recommended ? $recommended : null;
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
	 * The versions the app's source offers, or null when the source failed.
	 *
	 * @return list<string>|null
	 */
	private function availableVersions(string $appId): ?array {
		$result = $this->install->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return null;
		}

		return array_map(
			static fn (array $entry): string => $entry['version'],
			$result['availableVersions'] ?? []
		);
	}
}
