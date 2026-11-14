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

use OCA\Versioniq\Service\Source\AppStoreSource;

/**
 * The Nextcloud range an installed app declares in its info.xml
 * (`<nextcloud min-version="30" max-version="32"/>`), read from the parsed
 * app info, and how the running server relates to it.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
final class DeclaredServerRange {
	/**
	 * The range as a platform spec such as `>=30 <=32`, or null when the app
	 * declares no bound.
	 *
	 * @param array<array-key, mixed>|null $appInfo The app info as IAppManager::getAppInfo() returns it.
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public static function specOf(?array $appInfo): ?string {
		/** @var mixed $attributes */
		$attributes = $appInfo['dependencies']['nextcloud']['@attributes'] ?? null;
		if (!is_array($attributes)) {
			return null;
		}
		$bounds = [];
		foreach (['min-version' => '>=', 'max-version' => '<='] as $attribute => $operator) {
			/** @var mixed $bound */
			$bound = $attributes[$attribute] ?? null;
			if (is_string($bound) && preg_match('/^\d+(\.\d+)*$/', trim($bound)) === 1) {
				$bounds[] = $operator . trim($bound);
			}
		}

		return $bounds === [] ? null : implode(' ', $bounds);
	}

	/**
	 * Whether the server is inside the declared range: true, false, or null
	 * when the app declares none or a bound cannot be read.
	 *
	 * @param array<array-key, mixed>|null $appInfo
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public static function supports(string $serverVersion, ?array $appInfo): ?bool {
		$spec = self::specOf($appInfo);

		return $spec === null ? null : AppStoreSource::satisfiesPlatformSpec($serverVersion, $spec);
	}

	/**
	 * Whether only the declared maximum keeps the server out, so an override can help.
	 *
	 * @param array<array-key, mixed>|null $appInfo
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public static function serverTooNew(string $serverVersion, ?array $appInfo): bool {
		$spec = self::specOf($appInfo);

		return $spec !== null && AppStoreSource::serverAboveMaximum($serverVersion, $spec) === true;
	}
}
