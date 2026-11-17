<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\Policy\Policy;

/**
 * Pure candidate-selection logic for {@see \OCA\Versioniq\BackgroundJob\AutoUpdateJob}:
 * given the installed version, the versions available from the app's bound
 * source, and a policy level, returns the highest available version that is
 * strictly newer than installed and within the level's semver bound — or
 * null when nothing qualifies. No I/O, no side effects.
 *
 * Level semantics (see design.md "Level semantics"):
 *   - `patch`: same major.minor, greater patch
 *   - `minor`: same major, greater minor-or-patch
 *   - `all`: any greater version (raw `version_compare`)
 *
 * A version with a pre-release/build suffix, or that otherwise does not
 * parse as a clean `major.minor.patch` integer triple, never qualifies for
 * `patch`/`minor` (conservative) — only `all` can select it.
 *
 * @psalm-api
 */
class CandidateSelector {
	private const SEMVER_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

	/**
	 * Selects the highest qualifying candidate version, or null when none
	 * qualifies; see "Nightly policy execution through the standard
	 * installer" ("Patch-level update applied").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @param list<string> $availableVersions
	 */
	public function select(string $installedVersion, array $availableVersions, string $level): ?string {
		if ($level === Policy::LEVEL_NONE) {
			return null;
		}

		$candidates = array_values(array_filter(
			$availableVersions,
			fn (string $candidate): bool => version_compare($candidate, $installedVersion, '>')
				&& $this->withinLevel($installedVersion, $candidate, $level)
		));

		if ($candidates === []) {
			return null;
		}

		usort($candidates, static fn (string $a, string $b): int => version_compare($a, $b));

		return end($candidates);
	}

	private function withinLevel(string $installedVersion, string $candidate, string $level): bool {
		if ($level === Policy::LEVEL_ALL) {
			return true;
		}

		$installedParts = $this->parseSemver($installedVersion);
		$candidateParts = $this->parseSemver($candidate);
		if ($installedParts === null || $candidateParts === null) {
			// Non-semver (or pre-release/build suffixed) versions never
			// qualify for patch/minor — conservative, see design.md.
			return false;
		}

		if ($level === Policy::LEVEL_PATCH) {
			return $installedParts[0] === $candidateParts[0] && $installedParts[1] === $candidateParts[1];
		}

		// Policy::LEVEL_MINOR: same major, any minor/patch greater.
		return $installedParts[0] === $candidateParts[0];
	}

	/**
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function parseSemver(string $version): ?array {
		if (preg_match(self::SEMVER_PATTERN, trim($version), $matches) !== 1) {
			return null;
		}

		return [(int)$matches[1], (int)$matches[2], (int)$matches[3]];
	}
}
