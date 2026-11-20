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

/**
 * A version's age for the minimum-age hold (releases-minimum-age D2): its
 * `releasedAt` when the source gave one, else when Versioniq first saw it. It
 * is held while `releasedAt + minAgeDays` is later than now. A version without
 * a date is not treated as old: a forge draft published without one is exactly
 * the new release the hold exists for.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
class MinimumAgeRule {
	public function __construct(
		private FirstSeenStore $firstSeen,
	) {
	}

	/**
	 * When a version counts as released.
	 *
	 * @param array{version: string, releasedAt?: ?string} $entry A version entry.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function releasedAt(string $appId, array $entry, \DateTimeImmutable $now): \DateTimeImmutable {
		$released = $entry['releasedAt'] ?? null;
		if (is_string($released) && $released !== '') {
			try {
				return new \DateTimeImmutable($released);
			} catch (\Exception) {
				// An unreadable date counts as none: fall through to first seen.
			}
		}

		return $this->firstSeen->firstSeen($appId, $entry['version'], $now);
	}

	/**
	 * When the version may be installed: released plus the minimum age.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public static function eligibleAt(\DateTimeImmutable $releasedAt, int $minAgeDays): \DateTimeImmutable {
		return $releasedAt->modify('+' . max(0, $minAgeDays) . ' days');
	}

	/**
	 * Whether the version still waits. A minimum age of 0 holds nothing and records nothing.
	 *
	 * @param array{version: string, releasedAt?: ?string} $entry A version entry.
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	public function isHeld(string $appId, array $entry, int $minAgeDays, \DateTimeImmutable $now): bool {
		if ($minAgeDays <= 0) {
			return false;
		}

		return self::eligibleAt($this->releasedAt($appId, $entry, $now), $minAgeDays) > $now;
	}
}
