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

use OCA\Versioniq\Service\Pin\Pin;

/**
 * Why an affected installed version has, or has not, an installable fix
 * (design D2 of advisories-per-app-detail). A pure function over the
 * evaluated row and the version list the bound source returned in the same
 * sweep; it calls nothing.
 */
final class FixStatus {
	/** The source lists the recommended version and does not mark it incompatible. */
	public const INSTALLABLE = 'installable';
	/** No advisory names a patched version and no listed version clears them. */
	public const NO_FIX_PUBLISHED = 'no_fix_published';
	/** A fix is named, but the bound source does not list it. */
	public const NOT_IN_SOURCE = 'not_in_source';
	/** The source lists the fix, but marks it and every later version incompatible with this server. */
	public const NEEDS_NEWER_SERVER = 'needs_newer_server';

	/**
	 * The fix status of one evaluated row, or null when its installed version
	 * is not affected.
	 *
	 * The server row only ever gets `installable` or `no_fix_published`: its
	 * fix is a server upgrade, which no app source lists.
	 *
	 * @param array<string, mixed> $row
	 * @param list<array{version: string, changelog?: ?string, serverCompatible?: ?bool, releasedAt?: ?string}> $versions The versions the bound source listed
	 *
	 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-an-affected-version-without-an-installable-fix-says-why
	 */
	public static function for(array $row, array $versions): ?string {
		if (($row['state'] ?? null) !== AdvisoryService::STATE_VULNERABLE) {
			return null;
		}
		/** @var mixed $fix */
		$fix = $row['recommendedVersion'] ?? null;
		if (!is_string($fix) || $fix === '') {
			return self::NO_FIX_PUBLISHED;
		}
		if (($row['appId'] ?? null) === AdvisoryService::SERVER_KEY) {
			return self::INSTALLABLE;
		}

		if (!self::isListed($versions, $fix)) {
			return self::NOT_IN_SOURCE;
		}

		// The fix itself, or a later version, that this server can run. A
		// source that cannot tell (null) is not held against the version.
		foreach ($versions as $entry) {
			if (version_compare($entry['version'], $fix, '>=') && ($entry['serverCompatible'] ?? null) !== false) {
				return self::INSTALLABLE;
			}
		}

		return self::NEEDS_NEWER_SERVER;
	}

	/**
	 * The stored rows with `heldByPin` set: true where a pin holds the app
	 * below an installable fix. Read at request time, because a pin can
	 * change between two sweeps.
	 *
	 * @param array<string, mixed> $rows The stored rows, keyed by app id
	 * @param array<string, Pin> $pins The pins, keyed by app id
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/advisories-per-app-detail/specs/security-advisory-correlation/spec.md#requirement-an-affected-version-without-an-installable-fix-says-why
	 */
	public static function withPins(array $rows, array $pins): array {
		$held = [];
		/** @var mixed $row */
		foreach ($rows as $key => $row) {
			if (!is_array($row)) {
				/** @psalm-suppress MixedAssignment a malformed stored row is passed on untouched, as before */
				$held[$key] = $row;
				continue;
			}
			/** @var mixed $fix */
			$fix = $row['recommendedVersion'] ?? null;
			$pin = $pins[$key] ?? null;
			$row['heldByPin'] = $pin !== null
				&& ($row['fixStatus'] ?? null) === self::INSTALLABLE
				&& is_string($fix)
				&& version_compare($pin->version, $fix, '<');
			$held[$key] = $row;
		}

		return $held;
	}

	/**
	 * Whether the source lists exactly this version.
	 *
	 * @param list<array{version: string, changelog?: ?string, serverCompatible?: ?bool, releasedAt?: ?string}> $versions
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#2.1
	 */
	private static function isListed(array $versions, string $version): bool {
		foreach ($versions as $entry) {
			if (version_compare($entry['version'], $version, '==')) {
				return true;
			}
		}

		return false;
	}
}
