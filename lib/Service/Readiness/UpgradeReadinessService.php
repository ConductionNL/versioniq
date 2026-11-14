<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Readiness;

use OCA\Versioniq\Service\InstallerService;
use OCP\App\IAppManager;
use Throwable;

/**
 * Checks every installed app against a target Nextcloud major and a target
 * PHP version (inventory-upgrade-readiness D3). Each app gets one verdict:
 * `shipped`, `ready`, `updateFirst` (with the lowest newer version that runs
 * on both targets), `blocked` or `unknown`. The installed version's ranges
 * come from its own info.xml, so "what runs now" never waits on a source.
 *
 * @psalm-type ReadinessRow = array{id: string, label: string, installedVersion: string, verdict: string, version: ?string}
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
class UpgradeReadinessService {
	public const VERDICTS = ['shipped', 'ready', 'updateFirst', 'blocked', 'unknown'];

	public function __construct(
		private InstallerService $installer,
		private IAppManager $appManager,
	) {
	}

	/**
	 * The report for every installed app.
	 *
	 * @return array{server: string, php: string, apps: list<ReadinessRow>}
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	public function check(string $serverMajor, string $phpVersion): array {
		$apps = [];
		foreach ($this->installer->getInstalledApps() as $app) {
			$installed = $app['installedVersion'] ?? null;
			if (is_string($installed) && $installed !== '') {
				$apps[] = $this->verdictFor($app, $installed, $serverMajor, $phpVersion);
			}
		}

		return ['server' => $serverMajor, 'php' => $phpVersion, 'apps' => $apps];
	}

	/**
	 * One app's row.
	 *
	 * @param array<string, mixed> $app The app card from InstallerService::getInstalledApps().
	 * @return ReadinessRow
	 */
	private function verdictFor(array $app, string $installed, string $server, string $php): array {
		$row = [
			'id' => (string)$app['id'],
			'label' => (string)($app['label'] ?? $app['id']),
			'installedVersion' => $installed,
		];
		if (($app['isShipped'] ?? false) === true) {
			return $row + ['verdict' => 'shipped', 'version' => null];
		}

		$runsNow = $this->installedAdmits($row['id'], $server, $php);
		if ($runsNow === true) {
			return $row + ['verdict' => 'ready', 'version' => null];
		}

		return $row + $this->fromNewerVersions($row['id'], $installed, $runsNow === null, ['server' => $server, 'php' => $php]);
	}

	/**
	 * Whether the installed version's own info.xml admits both targets.
	 */
	private function installedAdmits(string $appId, string $server, string $php): ?bool {
		try {
			$info = $this->appManager->getAppInfo($appId);
		} catch (Throwable) {
			$info = null;
		}
		$ranges = VersionRanges::fromAppInfo(is_array($info) ? $info : []);

		return VersionRanges::admits($ranges['platformSpec'], $ranges['phpSpec'], $server, $php);
	}

	/**
	 * `updateFirst` with the lowest newer listed version that runs on both
	 * targets; else `blocked` when every range was readable, `unknown` when
	 * one was not or the source did not answer.
	 *
	 * @param array{server: string, php: string} $targets
	 * @return array{verdict: string, version: ?string}
	 */
	private function fromNewerVersions(string $appId, string $installed, bool $unknownNow, array $targets): array {
		$listing = $this->installer->getAppVersions($appId);
		$found = self::firstAdmitted(self::newerAscending($listing['versions'] ?? [], $installed), $targets);
		if ($found['version'] !== null) {
			return ['verdict' => 'updateFirst', 'version' => $found['version']];
		}
		$unknown = $found['unknown'] || $unknownNow || ($listing['statusCode'] ?? 0) !== 200;

		return ['verdict' => $unknown ? 'unknown' : 'blocked', 'version' => null];
	}

	/**
	 * The first entry that admits both targets, and whether an entry before
	 * it had no readable range.
	 *
	 * @param list<array<string, mixed>> $entries
	 * @param array{server: string, php: string} $targets
	 * @return array{version: ?string, unknown: bool}
	 */
	private static function firstAdmitted(array $entries, array $targets): array {
		$unknown = false;
		foreach ($entries as $entry) {
			$admits = VersionRanges::admits(self::text($entry['platformSpec'] ?? null), self::text($entry['phpSpec'] ?? null), $targets['server'], $targets['php']);
			if ($admits === true) {
				return ['version' => (string)$entry['version'], 'unknown' => $unknown];
			}
			$unknown = $unknown || $admits === null;
		}

		return ['version' => null, 'unknown' => $unknown];
	}

	/**
	 * The listed versions newer than the installed one, oldest first.
	 *
	 * @param mixed $versions The `versions` of a version listing.
	 * @return list<array<string, mixed>>
	 */
	private static function newerAscending(mixed $versions, string $installed): array {
		$newer = array_values(array_filter(
			is_array($versions) ? $versions : [],
			static fn (mixed $entry): bool => is_array($entry) && is_string($entry['version'] ?? null) && version_compare($entry['version'], $installed, '>'),
		));
		usort($newer, static fn (array $a, array $b): int => version_compare((string)$a['version'], (string)$b['version']));

		return $newer;
	}

	private static function text(mixed $value): string {
		return is_string($value) ? trim($value) : '';
	}
}
