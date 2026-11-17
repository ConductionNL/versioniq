<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * The kind of an update, by the size of the step from the installed version to
 * the candidate: `patch` (same major.minor), `minor` (same major) or `major`
 * (anything else). A version that does not parse as a clean `major.minor.patch`
 * counts as `major`, the rarest kind, so a strange version string never runs
 * more often than a real major (autoupdate-schedules, design D1).
 *
 * @psalm-api
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
final class UpdateType {
	public const PATCH = 'patch';
	public const MINOR = 'minor';
	public const MAJOR = 'major';

	/** @var list<string> */
	public const ALL = [self::PATCH, self::MINOR, self::MAJOR];

	private const SEMVER_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

	/**
	 * The kind of the step from `$installed` to `$candidate`.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public static function classify(string $installed, string $candidate): string {
		$from = self::parse($installed);
		$to = self::parse($candidate);
		if ($from === null || $to === null || $from[0] !== $to[0]) {
			return self::MAJOR;
		}

		return $from[1] === $to[1] ? self::PATCH : self::MINOR;
	}

	/**
	 * @return array{0:int,1:int,2:int}|null
	 */
	private static function parse(string $version): ?array {
		if (preg_match(self::SEMVER_PATTERN, trim($version), $matches) !== 1) {
			return null;
		}

		return [(int)$matches[1], (int)$matches[2], (int)$matches[3]];
	}
}
