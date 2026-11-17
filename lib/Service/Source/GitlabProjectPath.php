<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use InvalidArgumentException;

/**
 * The path-traversal guard for a GitLab binding's nested project path
 * (sources-gitlab-forge D3).
 */
final class GitlabProjectPath {
	/** Most segments a GitLab project path may have; GitLab nests at most 20 levels. */
	public const MAX_SEGMENTS = 20;

	/**
	 * Requires two to 20 segments, each in the owner/repo charset, and no `.`
	 * or `..` segment, so the guard holds for nested groups (CWE-22).
	 *
	 * @throws InvalidArgumentException
	 * @spec openspec/changes/sources-gitlab-forge/specs/external-sources/spec.md
	 */
	public static function assertSafe(string $path): void {
		$segments = explode('/', $path);
		if (count($segments) < 2 || count($segments) > self::MAX_SEGMENTS) {
			throw new InvalidArgumentException('gitlab path must have 2 to ' . self::MAX_SEGMENTS . ' segments');
		}
		foreach ($segments as $segment) {
			if (self::isSafeSegment($segment) === false) {
				throw new InvalidArgumentException('gitlab path contains an invalid segment');
			}
		}
	}

	/**
	 * Whether one path segment is in the owner/repo charset and is not `.` or `..`.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/specs/external-sources/spec.md
	 */
	private static function isSafeSegment(string $segment): bool {
		return $segment !== '.' && $segment !== '..' && preg_match('/^[A-Za-z0-9_.\-]+$/', $segment) === 1;
	}
}
