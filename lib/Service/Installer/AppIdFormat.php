<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

/**
 * The shape of an app id Versioniq installs or caches: lower case, starting
 * with a letter, then letters, digits, `_` and `-`. Anything else (a path, a
 * traversal, a space) is refused before it reaches a path lookup.
 */
final class AppIdFormat {
	private const PATTERN = '/^[a-z][a-z0-9_\-]*$/';

	/**
	 * @spec openspec/specs/version-management/spec.md
	 */
	public static function isValid(string $appId): bool {
		return preg_match(self::PATTERN, $appId) === 1;
	}
}
