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
 */
class AvailabilityService {
	/** Plain major.minor.patch, the rule CandidateSelector applies. */
	private const SEMVER_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

	public function __construct(
		private InstallerService $installerService,
		private PinStore $pinStore,
		private LoggerInterface $logger,
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
			$appId = (string)($app['id'] ?? '');
			$installedVersion = $app['installedVersion'] ?? null;
			if ($appId === '' || ($app['state'] ?? '') === 'notInstalled' || !is_string($installedVersion) || $installedVersion === '') {
				continue;
			}
			if (!$this->installerService->isManageableApp($appId)) {
				continue;
			}

			$pinned = $this->pinStore->get($appId) !== null;
			$boundSourceId = is_string($app['boundSourceId'] ?? null) ? $app['boundSourceId'] : null;

			if (microtime(true) >= $deadline) {
				$results[$appId] = $this->entry($installedVersion, [], $pinned, $boundSourceId, 'The update check ran out of time before it reached this app.');
				continue;
			}

			try {
				$listing = $this->installerService->getAppVersions($appId);
			} catch (\Throwable $error) {
				$this->logger->warning('AvailabilityService: listing versions failed', ['app' => $appId, 'message' => $error->getMessage()]);
				$results[$appId] = $this->entry($installedVersion, [], $pinned, $boundSourceId, $error->getMessage());
				continue;
			}

			$sourceId = is_string($listing['sourceId'] ?? null) ? $listing['sourceId'] : $boundSourceId;
			$error = ($listing['hasError'] ?? false) === true
				? (string)($listing['error'] ?? 'The source did not answer.')
				: null;
			$versions = $error === null && is_array($listing['availableVersions'] ?? null) ? $listing['availableVersions'] : [];

			$results[$appId] = $this->entry($installedVersion, $versions, $pinned, $sourceId, $error);
		}

		return $results;
	}

	/**
	 * @param list<array<string, mixed>> $versions
	 * @return array{installedVersion: string, newestVersion: ?string, newestCompatibleVersion: ?string, linesBehind: int, updateAvailable: bool, pinned: bool, sourceId: ?string, error: ?string}
	 */
	private function entry(string $installedVersion, array $versions, bool $pinned, ?string $sourceId, ?string $error): array {
		$newest = null;
		$newestCompatible = null;
		/** @var array<string, true> $lines */
		$lines = [];
		$installedParts = $this->parse($installedVersion);

		foreach ($versions as $entry) {
			$version = is_string($entry['version'] ?? null) ? trim($entry['version']) : '';
			$parts = $this->parse($version);
			if ($parts === null) {
				continue;
			}
			if ($newest === null || version_compare($version, $newest, '>')) {
				$newest = $version;
			}
			// A version the server cannot run is never an update: installing
			// it would be refused (SelectedReleaseInstallerService checks it).
			if (($entry['serverCompatible'] ?? null) === false) {
				continue;
			}
			if ($newestCompatible === null || version_compare($version, $newestCompatible, '>')) {
				$newestCompatible = $version;
			}
			if ($installedParts !== null && ($parts[0] > $installedParts[0] || ($parts[0] === $installedParts[0] && $parts[1] > $installedParts[1]))) {
				$lines[$parts[0] . '.' . $parts[1]] = true;
			}
		}

		return [
			'installedVersion' => $installedVersion,
			'newestVersion' => $newest,
			'newestCompatibleVersion' => $newestCompatible,
			'linesBehind' => count($lines),
			'updateAvailable' => $installedParts !== null && $newestCompatible !== null && version_compare($newestCompatible, $installedVersion, '>'),
			'pinned' => $pinned,
			'sourceId' => $sourceId,
			'error' => $error,
		];
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
