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
	 * The plan for a run: one member per app with a policy other than `none`,
	 * in policy order. An app that fails to plan is logged and left out, so
	 * one broken app never blocks the rest.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function plan(?\DateTimeImmutable $now = null): AutoUpdatePlan {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$members = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			$member = $this->planIsolated($appId, $policy, $now);
			if ($member !== null) {
				$members[] = $member;
			}
		}

		return new AutoUpdatePlan($members);
	}

	/**
	 * Plan one app; a failure is logged and leaves the app out of the plan.
	 */
	private function planIsolated(string $appId, Policy $policy, \DateTimeImmutable $now): ?AutoUpdateMember {
		try {
			return $this->planApp($appId, $policy, $now);
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
	 */
	private function planApp(string $appId, Policy $policy, \DateTimeImmutable $now): ?AutoUpdateMember {
		if ($this->stores->pinStore->get($appId) !== null) {
			return new AutoUpdateMember($appId, null, null, AutoUpdateMember::REASON_PINNED);
		}

		if (!$this->install->installerService->isManageableApp($appId)) {
			return null;
		}

		return $this->planCandidate($appId, $policy, $now);
	}

	/**
	 * The member for a managed, unpinned app: its candidate, or why it has
	 * none; null when the app is not installed.
	 */
	private function planCandidate(string $appId, Policy $policy, \DateTimeImmutable $now): ?AutoUpdateMember {
		$installedVersion = $this->installedVersionOf($appId);
		if ($installedVersion === null) {
			return null;
		}

		$entries = $this->availableEntries($appId);
		if ($entries === null) {
			return new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_SOURCE_ERROR);
		}

		// releases-minimum-age: the newest in-policy version old enough, an
		// admin's exception, or a security fix let through before the minimum age.
		$agePlan = $this->install->planner->plan(
			$appId,
			$installedVersion,
			$entries,
			$policy->level,
			$policy->minAgeDays ?? $this->stores->settingsStore->getMinAgeDays(),
			$now
		);
		$candidate = $agePlan['next'];
		if ($candidate === null) {
			return new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE);
		}

		if ($this->stores->attemptLedger->hasAttempted($appId, $candidate)) {
			// Never-retry — see "Failed attempt is not retried".
			return new AutoUpdateMember($appId, $installedVersion, $candidate, AutoUpdateMember::REASON_BLOCKED);
		}

		return new AutoUpdateMember($appId, $installedVersion, $candidate, null, self::bypassAdvisories($agePlan['securityBypass'], $candidate));
	}

	/**
	 * The advisories of a security fix the minimum-age plan lets through
	 * before the minimum age, when that fix is the version to install; []
	 * otherwise.
	 *
	 * @param array{version: string, advisories: list<string>, reason: string}|null $bypass
	 * @return list<string>
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	private static function bypassAdvisories(?array $bypass, string $candidate): array {
		if ($bypass === null || $bypass['version'] !== $candidate || $bypass['reason'] !== MinimumAgePlanner::REASON_SECURITY_FIX) {
			return [];
		}

		return $bypass['advisories'];
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
	 * The versions the app's source lists, with their release time when the
	 * source gives one, or null when it reported an error.
	 *
	 * @return list<array{version: string, releasedAt: ?string}>|null
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
}
