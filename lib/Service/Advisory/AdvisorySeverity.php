<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

/**
 * The severity values an advisory record may carry, and the one mapping every
 * advisory source applies to what it receives (issue #443). Before this the
 * App Store and forge sources turned a missing severity into `medium` while
 * the Nextcloud feed turned it into `unknown` and passed a present value
 * through un-lowered, so the same unscored advisory read differently by
 * source.
 *
 * - `low`, `medium`, `high`, `critical`: the scored levels, lower-cased.
 *   GitHub's `moderate` is the same level as `medium`.
 * - `unknown`: the advisory carries no severity, or one outside the levels
 *   above. It is never guessed up or down to a level.
 */
final class AdvisorySeverity {
	public const LOW = 'low';
	public const MEDIUM = 'medium';
	public const HIGH = 'high';
	public const CRITICAL = 'critical';
	public const UNKNOWN = 'unknown';

	private const LEVELS = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

	/** Other spellings of a level, as sources send them. */
	private const ALIASES = ['moderate' => self::MEDIUM];

	/**
	 * Maps a raw severity from any source to one of the documented values.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public static function normalize(mixed $raw): string {
		if (!is_string($raw)) {
			return self::UNKNOWN;
		}
		$value = strtolower(trim($raw));
		$value = self::ALIASES[$value] ?? $value;

		return in_array($value, self::LEVELS, true) ? $value : self::UNKNOWN;
	}
}
