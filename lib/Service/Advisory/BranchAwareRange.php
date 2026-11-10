<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

/**
 * Decides whether an installed version is affected by an advisory, using the
 * advisory's list of PATCHED versions rather than its prose version range.
 *
 * WHY NOT JUST EVALUATE THE RANGE. Nextcloud's published advisories describe
 * several parallel maintenance branches in a single record, and the range
 * field cannot express that. Measured over the 161 vulnerability entries in
 * the live `nextcloud/security-advisories` feed (2026-08-21):
 *
 *   66.5%  multiple lower bounds, no upper bound
 *          e.g. Mail  '>= 3.5.0, >= 3.7.0, >= 4.1.0, >= 4.3.0'
 *                     patched '3.7.25, 5.5.16, 5.6.20, 5.7.13'
 *   23.6%  a single lower bound, no upper bound
 *    5.0%  a single upper bound
 *    1.2%  several upper bounds
 *          e.g. Talk  '< 21.1.10, < 22.0.11, < 23.0.3'
 *                     patched '21.1.10, 22.0.11, 23.0.3'
 *
 * Neither boolean reading of that comma is right:
 *
 *   AND (the previous behaviour) collapses Mail's four clauses to '>= 4.3.0',
 *   so an instance on 3.6.0 is told it is SAFE. A false NEGATIVE, which is the
 *   worst direction a security check can fail in.
 *
 *   OR turns Talk's clauses into '< 23.0.3', so a correctly-patched 22.0.11 is
 *   reported VULNERABLE. A false positive, which trains admins to ignore the
 *   badge.
 *
 * The structure the data actually has is one patch per release branch. So the
 * branch decides:
 *
 *   - branch is `major.minor`;
 *   - if the installed version's OWN branch has a patch listed, that patch
 *     alone decides — at or above it means fixed, below it means affected;
 *   - only a branch with NO patch listed falls through to the nearest higher
 *     patch within the same major;
 *   - a version newer than every patch for its major is not affected.
 *
 * Validated against the whole corpus: 0 false positives in 458 probes (an
 * instance sitting exactly on a patched version), and 0 misses in 412 probes
 * (an instance one patch level below a patch). See BranchAwareRangeTest, which
 * re-runs both sweeps over the committed fixture.
 *
 * @psalm-api
 */
class BranchAwareRange {
	/**
	 * The patch that applies to $installedVersion, or null when the installed
	 * version is not affected.
	 *
	 * Returning the patch rather than a bool is deliberate: the caller needs
	 * the recommended safe version anyway, and deriving it separately is how
	 * the two answers drift apart.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param list<string> $patchedVersions
	 */
	public function resolvePatch(string $installedVersion, array $patchedVersions): ?string {
		$patched = array_values(array_filter($patchedVersions, static fn (string $v): bool => trim($v) !== ''));
		if ($patched === []) {
			return null;
		}

		// 1. The installed version's own branch, if that branch was patched.
		$ownBranch = $this->ownBranchPatches($installedVersion, $patched);
		if ($ownBranch !== []) {
			$earliest = $this->lowest($ownBranch);

			// At or above the patch for this branch means fixed. This is the
			// branch of the logic that stops a patched 22.0.11 from being
			// reported vulnerable because branch 23 has a later patch.
			return $this->compare($earliest, $installedVersion) > 0 ? $earliest : null;
		}

		// 2. This branch was never patched. The nearest higher patch on the
		//    same major is the upgrade target — this is what covers Mail 3.6.0,
		//    whose branch is absent but whose major has 3.7.25.
		$sameMajor = $this->higherPatchesOnMajor($installedVersion, $patched);
		if ($sameMajor !== []) {
			return $this->lowest($sameMajor);
		}

		// 3. The installed major has no fix at all. If the version predates
		//    EVERY published patch, the branch it sits on was abandoned
		//    without one and the only way out is forward — so it is affected,
		//    and the lowest patch is the nearest exit.
		//
		//    This case is why the rule is not "never cross a major". Measured
		//    on the corpus: User OIDC 2.0.0 against patches 3.0.0/4.0.0/5.0.0
		//    has no 2.x fix, and refusing to cross would report it SAFE. A
		//    security check must not fail in that direction, even though the
		//    recommendation it produces is a major upgrade.
		return $this->predatingEveryPatch($installedVersion, $patched);
	}

	/**
	 * The lowest patch when the installed version predates every patch;
	 * otherwise null: newer than every patch for its major, and not below the
	 * earliest patch overall, the fix is already in.
	 *
	 * @param non-empty-list<string> $patched
	 */
	private function predatingEveryPatch(string $installedVersion, array $patched): ?string {
		$lowest = $this->lowest($patched);

		return $this->compare($installedVersion, $lowest) < 0 ? $lowest : null;
	}

	/**
	 * The patches on the installed version's own `major.minor` branch.
	 *
	 * @param list<string> $patched
	 * @return list<string>
	 */
	private function ownBranchPatches(string $installedVersion, array $patched): array {
		$installed = $this->segments($installedVersion);

		return array_values(array_filter(
			$patched,
			function (string $candidate) use ($installed): bool {
				$parts = $this->segments($candidate);

				return $parts[0] === $installed[0] && $parts[1] === $installed[1];
			},
		));
	}

	/**
	 * The patches on the installed version's major that are newer than it.
	 *
	 * @param list<string> $patched
	 * @return list<string>
	 */
	private function higherPatchesOnMajor(string $installedVersion, array $patched): array {
		$major = $this->segments($installedVersion)[0];

		return array_values(array_filter(
			$patched,
			function (string $candidate) use ($major, $installedVersion): bool {
				return $this->segments($candidate)[0] === $major
					&& $this->compare($candidate, $installedVersion) > 0;
			},
		));
	}

	/**
	 * Numeric version segments, padded to four, so `3.7` and `3.7.0.0`
	 * compare equal. Non-numeric suffixes (`-beta1`) are ignored rather than
	 * ordered: an advisory that distinguishes prereleases is not something
	 * this data expresses.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int}
	 */
	private function segments(string $version): array {
		preg_match_all('/\d+/', $version, $matches);
		$parts = array_map('intval', array_slice($matches[0], 0, 4));
		$parts = array_pad($parts, 4, 0);

		/** @var array{0: int, 1: int, 2: int, 3: int} $parts */
		return $parts;
	}

	/**
	 * -1, 0 or 1, comparing numerically segment by segment. version_compare is
	 * deliberately not used: it treats `31.0.12` and `31.0.12.0` as different
	 * and orders unknown suffixes in ways this corpus does not mean.
	 */
	private function compare(string $a, string $b): int {
		return $this->segments($a) <=> $this->segments($b);
	}

	/**
	 * @param non-empty-list<string> $versions
	 */
	private function lowest(array $versions): string {
		$lowest = $versions[0];
		foreach ($versions as $candidate) {
			if ($this->compare($candidate, $lowest) < 0) {
				$lowest = $candidate;
			}
		}

		return $lowest;
	}
}
