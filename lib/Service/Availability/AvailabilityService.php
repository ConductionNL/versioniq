<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Availability;

use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Pin\SkipList;
use Psr\Log\LoggerInterface;

/**
 * Works out, per installed app, what runs, what is newest, and how far the app
 * is behind (inventory-pending-updates, design D2).
 *
 * It lists versions through InstallerService::getAppVersions(), the same path
 * the version picker and AutoUpdateJob use, so a binding, a trusted-source
 * rule or a cached compatibility answer means the same thing here as there.
 * It costs one source call per app, so it runs in AvailabilityRefreshJob and
 * `occ versioniq:updates --refresh`, never in a page request (issue #160).
 *
 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
 *
 * @psalm-api
 * @psalm-import-type InstalledAppCard from InstallerService
 */
class AvailabilityService {
	/** Plain major.minor.patch, the rule CandidateSelector applies. */
	private const SEMVER_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

	public function __construct(
		private InstallerService $installerService,
		private PinStore $pinStore,
		private LoggerInterface $logger,
		private ?SkipList $skipList = null,
	) {
	}

	/**
	 * Sweeps every installed, manageable app within the given wall-clock budget.
	 *
	 * An app reached after the budget ran out is recorded with an error rather
	 * than left out, so the page says "not checked" instead of nothing.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @return array<string, array{installedVersion: string, newestVersion: ?string, newestCompatibleVersion: ?string, linesBehind: int, updateAvailable: bool, pinned: bool, sourceId: ?string, error: ?string}>
	 */
	public function sweep(float $budgetSeconds): array {
		$deadline = microtime(true) + $budgetSeconds;
		$results = [];

		foreach ($this->installerService->getInstalledApps() as $app) {
			$installedVersion = $this->checkableVersion($app);
			if ($installedVersion === null) {
				continue;
			}
			$appId = $app['id'];
			$boundSourceId = is_string($app['boundSourceId'] ?? null) ? $app['boundSourceId'] : null;
			$check = new AppVersionCheck($installedVersion, $this->pinStore->get($appId) !== null, $boundSourceId);
			$results[$appId] = microtime(true) >= $deadline
				? $this->entry($check, [], 'The update check ran out of time before it reached this app.')
				: $this->listedEntry($appId, $check);
		}

		return $results;
	}

	/**
	 * The installed version of an app the sweep checks, or null for an app
	 * it skips: not installed, without a version, or not manageable.
	 *
	 * @param InstalledAppCard $app
	 */
	private function checkableVersion(array $app): ?string {
		$appId = $app['id'] ?? '';
		$installedVersion = $app['installedVersion'] ?? null;
		if ($appId === '' || ($app['state'] ?? '') === 'notInstalled' || !is_string($installedVersion) || $installedVersion === '') {
			return null;
		}

		return $this->installerService->isManageableApp($appId) ? $installedVersion : null;
	}

	/**
	 * The row for an app from its source's version listing; a listing that
	 * fails or reports an error is recorded as that error.
	 *
	 * @return array{installedVersion: string, newestVersion: ?string, newestCompatibleVersion: ?string, linesBehind: int, updateAvailable: bool, pinned: bool, sourceId: ?string, error: ?string}
	 */
	private function listedEntry(string $appId, AppVersionCheck $check): array {
		try {
			$listing = $this->installerService->getAppVersions($appId);
		} catch (\Throwable $error) {
			$this->logger->warning('AvailabilityService: listing versions failed', ['app' => $appId, 'message' => $error->getMessage()]);

			return $this->entry($check, [], $error->getMessage());
		}

		$error = ($listing['hasError'] ?? false) === true
			? ($listing['error'] ?? 'The source did not answer.')
			: null;
		$versions = $error === null && is_array($listing['availableVersions'] ?? null) ? $listing['availableVersions'] : [];
		$sourceId = is_string($listing['sourceId'] ?? null) ? $listing['sourceId'] : $check->sourceId;

		return $this->entry($check->withSource($sourceId), $this->withoutSkipped($appId, $versions), $error);
	}

	/**
	 * The listed versions an admin did not skip: a skipped version is never
	 * the pending update, and later versions are offered as usual.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
	 * @param list<array<string, mixed>> $versions
	 * @return list<array<string, mixed>>
	 */
	private function withoutSkipped(string $appId, array $versions): array {
		$skipped = $this->skipList?->versions($appId) ?? [];
		if ($skipped === []) {
			return $versions;
		}

		return array_values(array_filter(
			$versions,
			static fn (array $entry): bool => !in_array(trim((string)($entry['version'] ?? '')), $skipped, true)
		));
	}

	/**
	 * @param list<array<string, mixed>> $versions
	 * @return array{installedVersion: string, newestVersion: ?string, newestCompatibleVersion: ?string, linesBehind: int, updateAvailable: bool, pinned: bool, sourceId: ?string, error: ?string}
	 */
	private function entry(AppVersionCheck $check, array $versions, ?string $error): array {
		$installedParts = $this->parse($check->installedVersion);
		[$newest, $newestCompatible, $linesBehind] = $this->scanVersions($versions, $installedParts);

		return [
			'installedVersion' => $check->installedVersion,
			'newestVersion' => $newest,
			'newestCompatibleVersion' => $newestCompatible,
			'linesBehind' => $linesBehind,
			'updateAvailable' => $installedParts !== null && $newestCompatible !== null && version_compare($newestCompatible, $check->installedVersion, '>'),
			'pinned' => $check->pinned,
			'sourceId' => $check->sourceId,
			'error' => $error,
		];
	}

	/**
	 * The newest version, the newest the server can run, and how many release
	 * lines newer than the installed one the compatible versions span.
	 *
	 * @param list<array<string, mixed>> $versions
	 * @param array{0: int, 1: int, 2: int}|null $installedParts
	 * @return array{0: ?string, 1: ?string, 2: int}
	 */
	private function scanVersions(array $versions, ?array $installedParts): array {
		$newest = null;
		$newestCompatible = null;
		/** @var array<string, true> $lines */
		$lines = [];
		foreach ($this->parsedVersions($versions) as [$version, $parts, $compatible]) {
			$newest = self::newer($version, $newest);
			// A version the server cannot run is never an update: installing
			// it would be refused (SelectedReleaseInstallerService checks it).
			if (!$compatible) {
				continue;
			}
			$newestCompatible = self::newer($version, $newestCompatible);
			if (self::isNewerLine($parts, $installedParts)) {
				$lines[$parts[0] . '.' . $parts[1]] = true;
			}
		}

		return [$newest, $newestCompatible, count($lines)];
	}

	/**
	 * Each listed version that parses, with its parts and whether the server
	 * may run it (unknown counts as yes), in listing order.
	 *
	 * @param list<array<string, mixed>> $versions
	 * @return list<array{0: string, 1: array{0: int, 1: int, 2: int}, 2: bool}>
	 */
	private function parsedVersions(array $versions): array {
		$parsed = [];
		foreach ($versions as $entry) {
			$version = is_string($entry['version'] ?? null) ? trim((string)$entry['version']) : '';
			$parts = $this->parse($version);
			if ($parts !== null) {
				$parsed[] = [$version, $parts, ($entry['serverCompatible'] ?? null) !== false];
			}
		}

		return $parsed;
	}

	private static function newer(string $version, ?string $current): string {
		return $current === null || version_compare($version, $current, '>') ? $version : $current;
	}

	/**
	 * @param array{0: int, 1: int, 2: int} $parts
	 * @param array{0: int, 1: int, 2: int}|null $installed
	 */
	private static function isNewerLine(array $parts, ?array $installed): bool {
		return $installed !== null && ($parts[0] > $installed[0] || ($parts[0] === $installed[0] && $parts[1] > $installed[1]));
	}

	/**
	 * @return array{0: int, 1: int, 2: int}|null
	 */
	private function parse(string $version): ?array {
		if (preg_match(self::SEMVER_PATTERN, $version, $matches) !== 1) {
			return null;
		}

		return [(int)$matches[1], (int)$matches[2], (int)$matches[3]];
	}
}
