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

use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use Throwable;

/**
 * Plans a promotion from an earlier stage: the version, source and digest the
 * remote manifest names, the direction against what runs here and the
 * waiting-period verdict (install-multi-instance-promotion design D6, D7).
 *
 * @psalm-type PromotionPlan = array{connection: array{id: string, name: string, stage: string}, appId: string, version: string, sourceId: string, sha256: ?string, runningSince: ?string, installedVersion: string, direction: string, minDays: int, daysLeft: int, waitMet: bool}
 * @psalm-api
 */
class PromotionPlanner {
	private const SECONDS_PER_DAY = 86400;

	public function __construct(
		private InstanceConnectionStore $store,
		private RemoteInstanceReader $reader,
		private InstanceSettings $settings,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
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
			return new PromotionRefusal(PromotionService::REFUSED_UNKNOWN_CONNECTION, Http::STATUS_NOT_FOUND, sprintf('No connection named %s.', $connectionId));
		}

		$read = $fresh ? $this->freshRead($connection) : $this->reader->cached($connection);
		if (self::unreadable($read, $fresh)) {
			return new PromotionRefusal(PromotionService::REFUSED_UNREADABLE, Http::STATUS_BAD_GATEWAY, $read['error'] ?? RemoteInstanceReader::ERROR_UNREADABLE);
		}

		$remote = self::remoteApp($read['manifest'], $appId);

		return $remote === null
			? new PromotionRefusal(PromotionService::REFUSED_NOT_ON_REMOTE, Http::STATUS_CONFLICT, sprintf('%s does not run %s.', $connection->name, $appId))
			: $this->planFor($connection, $remote);
	}

	/**
	 * The local install of the planned version, with the earlier stage's source and recorded digest.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
	 *
	 * @param PromotionPlan $plan
	 */
	public static function installRequest(array $plan, bool $allowDowngrade): InstallRequest {
		$sha256 = $plan['sourceId'] === SourceBinding::KIND_APPSTORE ? null : $plan['sha256'];

		return new InstallRequest(
			new InstallTarget($plan['appId'], $plan['version'], $plan['sourceId'], $sha256),
			new InstallOptions(false, false, false, $allowDowngrade),
			new PinChoice(null, false),
		);
	}

	/**
	 * Whether a read has no manifest to plan from; a fresh read that failed counts, even with an older manifest kept.
	 *
	 * @param array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string} $read
	 */
	private static function unreadable(array $read, bool $fresh): bool {
		return $read['manifest'] === null || ($fresh && $read['error'] !== null);
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
	 * @return array{appId: string, version: string, sourceId: string, sha256: ?string, runningSince: ?string}|null
	 */
	private static function remoteApp(array $manifest, string $appId): ?array {
		/** @var mixed $apps */
		$apps = $manifest['apps'] ?? null;

		/** @var mixed $app */
		foreach (is_array($apps) ? $apps : [] as $app) {
			if (is_array($app) && ($app['appId'] ?? null) === $appId) {
				return self::remoteEntry($appId, $app);
			}
		}

		return null;
	}

	/**
	 * One manifest entry as a promotion reads it; null when no version runs.
	 *
	 * @param array<array-key, mixed> $app
	 * @return array{appId: string, version: string, sourceId: string, sha256: ?string, runningSince: ?string}|null
	 */
	private static function remoteEntry(string $appId, array $app): ?array {
		$version = self::nonEmptyOrNull($app['installedVersion'] ?? null);
		if ($version === null) {
			return null;
		}

		return [
			'appId' => $appId,
			'version' => $version,
			'sourceId' => self::nonEmptyOrNull($app['sourceId'] ?? null) ?? SourceBinding::KIND_APPSTORE,
			'sha256' => self::textOrNull($app['sha256'] ?? null),
			'runningSince' => self::textOrNull($app['runningSince'] ?? null),
		];
	}

	private static function textOrNull(mixed $value): ?string {
		return is_string($value) ? $value : null;
	}

	private static function nonEmptyOrNull(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * @param array{appId: string, version: string, sourceId: string, sha256: ?string, runningSince: ?string} $remote
	 * @return PromotionPlan
	 */
	private function planFor(InstanceConnection $connection, array $remote): array {
		$installed = $this->installedVersion($remote['appId']);
		$minDays = $this->settings->promotionMinDays();
		$daysLeft = $this->daysLeft($remote['runningSince'], $minDays);

		return [
			'connection' => ['id' => $connection->id, 'name' => $connection->name, 'stage' => $connection->stage],
			...$remote,
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

	private function installedVersion(string $appId): string {
		try {
			return $this->appManager->getAppVersion($appId, false);
		} catch (Throwable) {
			return '';
		}
	}

	private static function direction(string $installed, string $version): string {
		if ($installed === '') {
			return PromotionService::DIRECTION_UPGRADE;
		}

		$compared = version_compare($version, $installed);

		return $compared > 0 ? PromotionService::DIRECTION_UPGRADE : ($compared < 0 ? PromotionService::DIRECTION_DOWNGRADE : PromotionService::DIRECTION_SAME);
	}
}
