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
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use Throwable;

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
 * @psalm-type PromotionPlan = array{connection: array{id: string, name: string, stage: string}, appId: string, version: string, sourceId: string, sha256: ?string, runningSince: ?string, installedVersion: string, direction: string, minDays: int, daysLeft: int, waitMet: bool}
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

	private const SECONDS_PER_DAY = 86400;

	public function __construct(
		private InstanceConnectionStore $store,
		private RemoteInstanceReader $reader,
		private InstallerService $installer,
		private InstanceSettings $settings,
		private AuditLogger $auditLogger,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
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
		$connection = $this->store->get($connectionId);
		if ($connection === null) {
			return self::refusal(self::REFUSED_UNKNOWN_CONNECTION, Http::STATUS_NOT_FOUND, sprintf('No connection named %s.', $connectionId));
		}

		$read = $fresh ? $this->freshRead($connection) : $this->reader->cached($connection);
		if ($read['manifest'] === null || ($fresh && $read['error'] !== null)) {
			return self::refusal(self::REFUSED_UNREADABLE, Http::STATUS_BAD_GATEWAY, $read['error'] ?? RemoteInstanceReader::ERROR_UNREADABLE);
		}

		$remote = self::remoteApp($read['manifest'], $appId);
		if ($remote === null) {
			return self::refusal(self::REFUSED_NOT_ON_REMOTE, Http::STATUS_CONFLICT, sprintf('%s does not run %s.', $connection->name, $appId));
		}

		return $this->planFor($connection, $appId, $remote);
	}

	/**
	 * Promote $appId from a connection: read its manifest again, check the
	 * planned version still runs there and the waiting period, install
	 * through the standard installer and audit the attempt.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 * @param ?string $plannedVersion The version the admin saw, or null to take what runs now
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	public function promote(string $connectionId, string $appId, bool $allowDowngrade = false, bool $overrideMinDays = false, ?string $plannedVersion = null, bool $dryRun = false): array {
		$plan = $this->plan($connectionId, $appId, fresh: true);
		if ($plan instanceof PromotionRefusal) {
			return $plan->toResult();
		}

		$refusal = $this->planRefusal($plan, $plannedVersion, $overrideMinDays);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($dryRun) {
			return ['statusCode' => Http::STATUS_OK, 'payload' => ['dryRun' => true, 'plan' => $plan]];
		}

		$result = $this->installer->installAppVersion(new InstallRequest(
			new InstallTarget($appId, $plan['version'], $plan['sourceId']),
			new InstallOptions(false, false, false, $allowDowngrade, $plan['sourceId'] === SourceBinding::KIND_APPSTORE ? null : $plan['sha256']),
			new PinChoice(null, false),
		));
		$this->audit($plan, $result['statusCode'] < 300, $overrideMinDays && !$plan['waitMet']);

		$result['payload'] += ['promotedFrom' => $plan['connection'], 'previousVersion' => $plan['installedVersion']];

		return $result;
	}

	/**
	 * @return array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}
	 */
	private function freshRead(InstanceConnection $connection): array {
		try {
			return $this->reader->fetch($connection);
		} catch (Throwable) {
			return ['manifest' => null, 'fetchedAt' => null, 'error' => RemoteInstanceReader::ERROR_UNREACHABLE, 'checkedAt' => null];
		}
	}

	/**
	 * The remote's entry for $appId when it runs a version of it.
	 *
	 * @param array<string, mixed> $manifest
	 * @return array{version: string, sourceId: string, sha256: ?string, runningSince: ?string}|null
	 */
	private static function remoteApp(array $manifest, string $appId): ?array {
		/** @var mixed $apps */
		$apps = $manifest['apps'] ?? null;
		if (!is_array($apps)) {
			return null;
		}

		/** @var mixed $app */
		foreach ($apps as $app) {
			if (is_array($app) && ($app['appId'] ?? null) === $appId) {
				return self::remoteEntry($app);
			}
		}

		return null;
	}

	/**
	 * One manifest entry as a promotion reads it; null when no version runs.
	 *
	 * @param array<array-key, mixed> $app
	 * @return array{version: string, sourceId: string, sha256: ?string, runningSince: ?string}|null
	 */
	private static function remoteEntry(array $app): ?array {
		/** @var mixed $version */
		$version = $app['installedVersion'] ?? null;
		if (!is_string($version) || $version === '') {
			return null;
		}

		/** @var mixed $sourceId */
		$sourceId = $app['sourceId'] ?? null;
		/** @var mixed $sha */
		$sha = $app['sha256'] ?? null;
		/** @var mixed $since */
		$since = $app['runningSince'] ?? null;

		return [
			'version' => $version,
			'sourceId' => is_string($sourceId) && $sourceId !== '' ? $sourceId : SourceBinding::KIND_APPSTORE,
			'sha256' => is_string($sha) ? $sha : null,
			'runningSince' => is_string($since) ? $since : null,
		];
	}

	/**
	 * @param array{version: string, sourceId: string, sha256: ?string, runningSince: ?string} $remote
	 * @return PromotionPlan
	 */
	private function planFor(InstanceConnection $connection, string $appId, array $remote): array {
		$installed = $this->installedVersion($appId);
		$minDays = $this->settings->promotionMinDays();
		$daysLeft = $this->daysLeft($remote['runningSince'], $minDays);

		return [
			'connection' => ['id' => $connection->id, 'name' => $connection->name, 'stage' => $connection->stage],
			'appId' => $appId,
			'version' => $remote['version'],
			'sourceId' => $remote['sourceId'],
			'sha256' => $remote['sha256'],
			'runningSince' => $remote['runningSince'],
			'installedVersion' => $installed,
			'direction' => self::direction($installed, $remote['version']),
			'minDays' => $minDays,
			'daysLeft' => $daysLeft,
			'waitMet' => $daysLeft === 0,
		];
	}

	/**
	 * The days still to wait: 0 when the period is off or over; the whole
	 * period when since when the version runs is unknown.
	 */
	private function daysLeft(?string $runningSince, int $minDays): int {
		if ($minDays === 0) {
			return 0;
		}

		$since = $runningSince === null ? false : strtotime($runningSince);
		if ($since === false) {
			return $minDays;
		}

		$now = $this->timeFactory->getTime();
		$daysRun = intdiv(max(0, $now - $since), self::SECONDS_PER_DAY);

		return max(0, $minDays - $daysRun);
	}

	/**
	 * The version moved on the remote, or the waiting period is not met without an override.
	 *
	 * @param PromotionPlan $plan
	 * @return array{statusCode: int, payload: array<string, mixed>}|null
	 */
	private function planRefusal(array $plan, ?string $plannedVersion, bool $overrideMinDays): ?array {
		if ($plannedVersion !== null && $plannedVersion !== $plan['version']) {
			return ['statusCode' => Http::STATUS_CONFLICT, 'payload' => [
				'code' => self::REFUSED_VERSION_MOVED,
				'message' => sprintf('%s no longer runs %s; it runs %s now.', $plan['connection']['name'], $plannedVersion, $plan['version']),
				'plan' => $plan,
			]];
		}

		if ($plan['waitMet'] || $overrideMinDays) {
			return null;
		}

		$message = $plan['runningSince'] === null
			? sprintf('Since when %s runs %s is unknown, so the waiting period of %d days is not met.', $plan['connection']['name'], $plan['version'], $plan['minDays'])
			: sprintf('%d days are left in the waiting period of %d days.', $plan['daysLeft'], $plan['minDays']);

		return ['statusCode' => Http::STATUS_CONFLICT, 'payload' => [
			'code' => self::REFUSED_WAITING_PERIOD,
			'message' => $message,
			'daysLeft' => $plan['daysLeft'],
			'plan' => $plan,
		]];
	}

	/**
	 * @param PromotionPlan $plan
	 */
	private function audit(array $plan, bool $succeeded, bool $overridden): void {
		$message = sprintf('Promoted from %s (%s)', $plan['connection']['name'], $plan['connection']['stage']);
		if ($overridden) {
			$message .= sprintf('; the waiting period of %d days was overridden with %d days left', $plan['minDays'], $plan['daysLeft']);
		}

		$this->auditLogger->record(
			new AuditSubject($this->userSession->getUser()?->getUID() ?? '', $plan['appId'], AuditLogger::OPERATION_PROMOTE),
			new AuditedVersions($plan['installedVersion'] === '' ? null : $plan['installedVersion'], $plan['version'], $plan['sourceId']),
			$succeeded ? AuditLogger::STATUS_SUCCESS : AuditLogger::STATUS_FAILURE,
			$message,
		);
	}

	private function installedVersion(string $appId): string {
		try {
			return $this->appManager->getAppVersion($appId, false);
		} catch (Throwable) {
			return '';
		}
	}

	private static function direction(string $installed, string $version): string {
		if ($installed === '') {
			return self::DIRECTION_UPGRADE;
		}

		$compared = version_compare($version, $installed);

		return $compared > 0 ? self::DIRECTION_UPGRADE : ($compared < 0 ? self::DIRECTION_DOWNGRADE : self::DIRECTION_SAME);
	}

	private static function refusal(string $code, int $status, string $message): PromotionRefusal {
		return new PromotionRefusal($code, $status, $message);
	}
}
