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
		$patchPolicy ??= PatchPolicy::none();
		$first = [];
		$later = [];
		$holds = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			$planned = $this->planIsolated($appId, $policy->level, $advisories, $patchPolicy, $holds);
			if ($planned === null) {
				continue;
			}

			if ($planned['first']) {
				$first[] = $planned['member'];
			} else {
				$later[] = $planned['member'];
			}
		}

		return new AutoUpdatePlan(array_merge($first, $later), $holds);
	}

	/**
	 * Plan one app; a failure is logged and leaves the app out of the plan.
	 *
	 * @param array{rows: array<string, mixed>, fresh: bool} $advisories
	 * @param array<string, string> $holds Why a fix was held back, by app id.
	 * @return array{member: AutoUpdateMember, first: bool}|null
	 */
	private function planIsolated(string $appId, string $level, array $advisories, PatchPolicy $patchPolicy, array &$holds): ?array {
		try {
			return $this->planApp($appId, $level, $advisories, $patchPolicy, $holds);
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
	 * @param array{rows: array<string, mixed>, fresh: bool} $advisories
	 * @param array<string, string> $holds
	 * @return array{member: AutoUpdateMember, first: bool}|null
	 */
	private function planApp(string $appId, string $level, array $advisories, PatchPolicy $patchPolicy, array &$holds): ?array {
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

		return $this->planSecurityFirst($appId, $level, $installedVersion, $available, $advisories, $patchPolicy, $holds);
	}

	/**
	 * The member for a managed, unpinned, installed app: a security fix the
	 * patch policy handles at the next window runs first, one it sets to by
	 * hand is held, a scheduled one installs with the other updates, and
	 * every other app takes the highest version within its level among the
	 * kinds the patch policy does not set to by hand.
	 *
	 * @param list<string> $available
	 * @param array{rows: array<string, mixed>, fresh: bool} $advisories
	 * @param array<string, string> $holds
	 * @return array{member: AutoUpdateMember, first: bool}
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
	 */
	private function planSecurityFirst(string $appId, string $level, string $installedVersion, array $available, array $advisories, PatchPolicy $patchPolicy, array &$holds): array {
		/** @var mixed $stored */
		$stored = $advisories['rows'][$appId] ?? null;
		/** @var array<string, mixed>|null $row */
		$row = is_array($stored) ? $stored : null;
		$fix = $this->securityFix($appId, $level, $installedVersion, $available, $row, $advisories['fresh'], $holds);
		if ($fix === null) {
			// Nothing a security fix can do: a `security` app is done, any
			// other level updates as usual.
			if ($level === Policy::LEVEL_SECURITY) {
				return ['member' => new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE), 'first' => false];
			}

			return ['member' => $this->regularMember($appId, $level, $installedVersion, $available, $patchPolicy), 'first' => false];
		}

		/** @var mixed $listed */
		$listed = $row['advisories'] ?? null;
		$advisoryRows = is_array($listed) ? array_values($listed) : [];
		$handling = $patchPolicy->securityHandling(PatchPolicy::highestSeverity($advisoryRows));
		if ($handling === PatchPolicy::HANDLING_MANUAL) {
			$holds[$appId] = SecurityHoldStore::REASON_MANUAL;

			return ['member' => new AutoUpdateMember($appId, $installedVersion, $fix, AutoUpdateMember::REASON_HELD), 'first' => false];
		}

		if ($handling === PatchPolicy::HANDLING_NEXT_WINDOW) {
			return ['member' => $this->memberFor($appId, $installedVersion, $fix), 'first' => true];
		}

		// `scheduled`: the fix installs with the other updates.
		if ($level === Policy::LEVEL_SECURITY) {
			return ['member' => $this->memberFor($appId, $installedVersion, $fix), 'first' => false];
		}

		return ['member' => $this->regularMember($appId, $level, $installedVersion, $available, $patchPolicy), 'first' => false];
	}

	/**
	 * The highest version within the app's level among the kinds the patch
	 * policy does not set to by hand, or why there is none.
	 *
	 * @param list<string> $available
	 */
	private function regularMember(string $appId, string $level, string $installedVersion, array $available, PatchPolicy $patchPolicy): AutoUpdateMember {
		$allowed = array_values(array_filter(
			$available,
			static fn (string $version): bool => $patchPolicy->regularHandling(PatchPolicy::kindOf($installedVersion, $version)) !== PatchPolicy::HANDLING_MANUAL,
		));
		$candidate = $this->install->candidateSelector->select($installedVersion, $allowed, $level);
		if ($candidate === null) {
			return new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE);
		}

		return $this->memberFor($appId, $installedVersion, $candidate);
	}

	/**
	 * A member that installs the version, unless that exact version was
	 * attempted before (never-retry — see "Failed attempt is not retried").
	 */
	private function memberFor(string $appId, string $installedVersion, string $version): AutoUpdateMember {
		if ($this->stores->attemptLedger->hasAttempted($appId, $version)) {
			return new AutoUpdateMember($appId, $installedVersion, $version, AutoUpdateMember::REASON_BLOCKED);
		}

		return new AutoUpdateMember($appId, $installedVersion, $version, null);
	}

	/**
	 * The security fix this app's level can install now: for the `security`
	 * level the fix the advisory check recommends (see SecurityCandidate),
	 * for any other level that same fix when the level's own selection
	 * includes it. Null when the app is not affected or the fix is out of
	 * reach; a `security` app records why.
	 *
	 * @param list<string> $available
	 * @param array<string, mixed>|null $row The app's advisory row.
	 * @param array<string, string> $holds Why a fix was held back, by app id.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
	 */
	private function securityFix(string $appId, string $level, string $installedVersion, array $available, ?array $row, bool $fresh, array &$holds): ?string {
		if ($level === Policy::LEVEL_SECURITY) {
			$decision = SecurityCandidate::decide($installedVersion, $row, $available, $fresh);
			if ($decision['version'] === null && $decision['reason'] !== SecurityCandidate::REASON_NOT_AFFECTED) {
				$this->logger->info('AutoUpdateJob: no security fix installed', ['appId' => $appId, 'reason' => $decision['reason']]);
				$holds[$appId] = (string)$decision['reason'];
			}

			return $decision['version'];
		}

		$recommended = $row['recommendedVersion'] ?? null;
		if (!$fresh || ($row['state'] ?? null) !== AdvisoryService::STATE_VULNERABLE
			|| !is_string($recommended) || !in_array($recommended, $available, true)) {
			return null;
		}

		return $this->install->candidateSelector->select($installedVersion, [$recommended], $level) === $recommended ? $recommended : null;
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
