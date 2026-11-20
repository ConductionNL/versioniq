<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\AutoUpdate\AgeQuery;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateInstall;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdatePlanStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\PlanTarget;
use OCA\Versioniq\Service\Policy\Policy;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every six hours, works out what the next automatic-update run would do for
 * each app with a policy, and stores it for the Automatic updates overview
 * (releases-minimum-age D4). It runs whether or not automatic updates are
 * switched on, so an admin can see what waits before turning them on. It
 * never installs anything; the update job always plans afresh.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 *
 * @psalm-api
 */
class AutoUpdatePlanJob extends TimedJob {
	public const BUDGET_SECONDS = 600.0;

	public const STATE_PLANNED = 'planned';
	public const STATE_PINNED = 'pinned';
	public const STATE_SOURCE_ERROR = 'source_error';
	public const STATE_FAILED = 'failed';

	private const INTERVAL_SECONDS = 6 * 3600;

	private LoggerInterface $logger;

	public function __construct(
		private JobRuntime $runtime,
		private AutoUpdateStores $stores,
		private AutoUpdateInstall $install,
		private AutoUpdatePlanStore $planStore,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	protected function run($argument): void {
		$started = microtime(true);
		$now = \DateTimeImmutable::createFromInterface($this->runtime->time->getDateTime('now', new \DateTimeZone('UTC')));
		$instanceMinAge = $this->stores->settingsStore->getMinAgeDays();

		$plans = [];
		foreach ($this->stores->policyStore->all() as $appId => $policy) {
			if ($policy->level === Policy::LEVEL_NONE) {
				continue;
			}
			if (microtime(true) - $started > self::BUDGET_SECONDS) {
				$this->logger->warning('AutoUpdatePlanJob: the time budget ran out; the remaining apps were not planned', ['next' => $appId]);
				break;
			}
			$entry = $this->planIsolated(new PlanTarget((string)$appId, $policy->level, $policy->minAgeDays ?? $instanceMinAge, $now));
			if ($entry !== null) {
				$plans[(string)$appId] = $entry;
			}
		}

		$this->planStore->save($plans, $this->runtime->time->getTime());
	}

	/**
	 * One app's plan; a failure is logged and recorded for that app only, so
	 * one broken app never stops the rest.
	 *
	 * @return array<string, mixed>|null Null for an app that is not managed or not installed.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	private function planIsolated(PlanTarget $target): ?array {
		try {
			return $this->planApp($target);
		} catch (\Throwable $error) {
			$this->logger->warning('AutoUpdatePlanJob: failed to plan an app', ['appId' => $target->appId, 'message' => $error->getMessage()]);

			return self::entry(self::STATE_FAILED, $target->minAgeDays);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function planApp(PlanTarget $target): ?array {
		$appId = $target->appId;
		$minAgeDays = $target->minAgeDays;
		if ($this->stores->pinStore->get($appId) !== null) {
			// A pinned app never updates, so its source is not asked.
			return self::entry(self::STATE_PINNED, $minAgeDays);
		}
		if (!$this->install->installerService->isManageableApp($appId)) {
			return null;
		}
		$installed = $this->install->appManager->getAppVersion($appId, false);
		if ($installed === '') {
			return null;
		}

		$result = $this->install->installerService->getAppVersions($appId);
		if (($result['hasError'] ?? false) === true) {
			return self::entry(self::STATE_SOURCE_ERROR, $minAgeDays, $installed);
		}
		$entries = [];
		foreach ($result['availableVersions'] ?? [] as $listed) {
			$released = $listed['releasedAt'] ?? null;
			$entries[] = ['version' => (string)$listed['version'], 'releasedAt' => is_string($released) ? $released : null];
		}
		if ($this->install->planner === null) {
			return self::entry(self::STATE_FAILED, $minAgeDays, $installed);
		}

		$plan = $this->install->planner->plan(AgeQuery::of($target, $installed, $entries));

		return array_replace(self::entry(self::STATE_PLANNED, $minAgeDays, $installed), [
			'next' => $plan['next'],
			'heldBack' => $plan['heldBack'],
			'securityBypass' => $plan['securityBypass'],
			'exception' => $plan['exception'],
		]);
	}

	/**
	 * The fields every plan entry carries; a planned entry adds the plan itself.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(string $state, int $minAgeDays, ?string $installed = null): array {
		return [
			'state' => $state,
			'installedVersion' => $installed,
			'minAgeDays' => $minAgeDays,
			'next' => null,
			'heldBack' => [],
			'securityBypass' => null,
			'exception' => null,
		];
	}
}
