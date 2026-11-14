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
	public function plan(): AutoUpdatePlan {
		$members = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}

			try {
				$member = $this->planApp($appId, $policy->level);
			} catch (\Throwable $error) {
				// Per-app isolation — see "the job MUST proceed to the next app
				// after any failure".
				$this->logger->warning('AutoUpdateJob: failed to process an app', [
					'appId' => $appId,
					'message' => $error->getMessage(),
				]);
				continue;
			}

			if ($member !== null) {
				$members[] = $member;
			}
		}

		return new AutoUpdatePlan($members);
	}

	/**
	 * One app's place in the plan, or null when it has no place: it is not
	 * manageable here, or not installed.
	 */
	private function planApp(string $appId, string $level): ?AutoUpdateMember {
		if ($this->stores->pinStore->get($appId) !== null) {
			return new AutoUpdateMember($appId, null, null, AutoUpdateMember::REASON_PINNED);
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
			return new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_SOURCE_ERROR);
		}

		$candidate = $this->install->candidateSelector->select($installedVersion, $available, $level);
		if ($candidate === null) {
			return new AutoUpdateMember($appId, $installedVersion, null, AutoUpdateMember::REASON_NO_CANDIDATE);
		}

		if ($this->stores->attemptLedger->hasAttempted($appId, $candidate)) {
			// Never-retry — see "Failed attempt is not retried".
			return new AutoUpdateMember($appId, $installedVersion, $candidate, AutoUpdateMember::REASON_BLOCKED);
		}

		return new AutoUpdateMember($appId, $installedVersion, $candidate, null);
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
