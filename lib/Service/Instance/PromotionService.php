<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Instance;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\IUserSession;

/**
 * Promotes the version an earlier stage runs to this instance
 * (install-multi-instance-promotion design D6, D7).
 *
 * Promotion is always pulled: the remote manifest is read with the stored
 * credential, and the install is a local installAppVersion() call with the
 * earlier stage's source and recorded digest, so the trusted-source
 * allowlist, the pin guard, the downgrade guard and maintenance mode apply
 * unchanged. Each attempt that reaches the installer is audited as `promote`.
 *
 * @psalm-import-type PromotionPlan from PromotionPlanner
 * @psalm-api
 */
class PromotionService {
	public const DIRECTION_UPGRADE = 'upgrade';
	public const DIRECTION_DOWNGRADE = 'downgrade';
	public const DIRECTION_SAME = 'same';

	/** Why a promotion cannot be planned, with the status it answers. */
	public const REFUSED_UNKNOWN_CONNECTION = 'unknown_connection';
	public const REFUSED_UNREADABLE = 'connection_unreadable';
	public const REFUSED_NOT_ON_REMOTE = 'not_on_remote';
	public const REFUSED_VERSION_MOVED = 'version_moved';
	public const REFUSED_WAITING_PERIOD = 'waiting_period';

	public function __construct(
		private PromotionPlanner $planner,
		private InstallerService $installer,
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
	) {
	}

	/**
	 * What promoting $appId from a connection would install, from its cached
	 * manifest, or from a fresh read when $fresh is true; a refusal when it
	 * cannot be planned.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
	 * @return PromotionPlan|PromotionRefusal
	 */
	public function plan(string $connectionId, string $appId, bool $fresh = false): array|PromotionRefusal {
		return $this->planner->plan($connectionId, $appId, $fresh);
	}

	/**
	 * Promote $appId from a connection: read its manifest again, check the
	 * planned version still runs there and the waiting period, install
	 * through the standard installer and audit the attempt.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	public function promote(string $connectionId, string $appId, PromotionOptions $options = new PromotionOptions()): array {
		$plan = $this->plan($connectionId, $appId, fresh: true);

		return $plan instanceof PromotionRefusal ? $plan->toResult() : $this->promotePlanned($plan, $options);
	}

	/**
	 * Check a fresh plan, install it through the standard installer and audit the attempt.
	 *
	 * @param PromotionPlan $plan
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private function promotePlanned(array $plan, PromotionOptions $options): array {
		$refusal = self::planRefusal($plan, $options);
		if ($refusal !== null || $options->dryRun) {
			return $refusal ?? ['statusCode' => Http::STATUS_OK, 'payload' => ['dryRun' => true, 'plan' => $plan]];
		}

		$result = $this->installer->installAppVersion(PromotionPlanner::installRequest($plan, $options->allowDowngrade));
		$this->auditLogger->record(
			new AuditSubject($this->actor(), $plan['appId'], AuditLogger::OPERATION_PROMOTE),
			new AuditedVersions($plan['installedVersion'] === '' ? null : $plan['installedVersion'], $plan['version'], $plan['sourceId']),
			$result['statusCode'] < 300 ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE,
			self::auditMessage($plan, $options->overrideMinDays && !$plan['waitMet']),
		);

		$result['payload'] += ['promotedFrom' => $plan['connection'], 'previousVersion' => $plan['installedVersion']];

		return $result;
	}

	private function actor(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * The version moved on the remote, or the waiting period is not met without an override.
	 *
	 * @param PromotionPlan $plan
	 * @return array{statusCode: int, payload: array<string, mixed>}|null
	 */
	private static function planRefusal(array $plan, PromotionOptions $options): ?array {
		$planned = $options->plannedVersion ?? $plan['version'];
		if ($planned !== $plan['version']) {
			return self::conflict(['code' => self::REFUSED_VERSION_MOVED, 'message' => sprintf('%s no longer runs %s; it runs %s now.', $plan['connection']['name'], $planned, $plan['version']), 'plan' => $plan]);
		}

		if ($plan['waitMet'] || $options->overrideMinDays) {
			return null;
		}

		return self::conflict(['code' => self::REFUSED_WAITING_PERIOD, 'message' => self::waitingMessage($plan), 'daysLeft' => $plan['daysLeft'], 'plan' => $plan]);
	}

	/**
	 * Why the waiting period is not met.
	 *
	 * @param PromotionPlan $plan
	 */
	private static function waitingMessage(array $plan): string {
		return $plan['runningSince'] === null
			? sprintf('Since when %s runs %s is unknown, so the waiting period of %d days is not met.', $plan['connection']['name'], $plan['version'], $plan['minDays'])
			: sprintf('%d days are left in the waiting period of %d days.', $plan['daysLeft'], $plan['minDays']);
	}

	/**
	 * A 409 answer with a refusal payload: its code, its message and the details after them.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private static function conflict(array $payload): array {
		return ['statusCode' => Http::STATUS_CONFLICT, 'payload' => $payload];
	}

	/**
	 * The audit message of a promotion, naming an overridden waiting period.
	 *
	 * @param PromotionPlan $plan
	 */
	private static function auditMessage(array $plan, bool $overridden): string {
		$message = sprintf('Promoted from %s (%s)', $plan['connection']['name'], $plan['connection']['stage']);

		return $overridden
			? $message . sprintf('; the waiting period of %d days was overridden with %d days left', $plan['minDays'], $plan['daysLeft'])
			: $message;
	}
}
