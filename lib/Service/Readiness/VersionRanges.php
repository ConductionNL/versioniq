<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Readiness;

use OCA\Versioniq\Service\Source\AppStoreSource;

/**
 * The server and PHP range of one app version, read from an installed app's
 * info.xml or carried by a listed version, and whether a pair of targets
 * falls inside them (inventory-upgrade-readiness D3).
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
 */
final class VersionRanges {
	/**
	 * The ranges an installed app declares in its info.xml, as App Store
	 * style specs (`>=30 <=33`); '' where it declares no bound.
	 *
	 * @param array<array-key, mixed> $info The parsed info.xml, as IAppManager::getAppInfo() returns it.
	 * @return array{platformSpec: string, phpSpec: string}
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	public static function fromAppInfo(array $info): array {
		$dependencies = is_array($info['dependencies'] ?? null) ? $info['dependencies'] : [];

		return [
			'platformSpec' => self::specOf($dependencies['nextcloud'] ?? null),
			'phpSpec' => self::specOf($dependencies['php'] ?? null),
		];
	}

	/**
	 * Whether a version with these ranges runs on both targets: null when it
	 * declares no server range ('') or a range cannot be read, true when it
	 * declares no PHP range and the server range admits the target.
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-an-admin-checks-every-installed-app-against-a-target-server-major-and-php-version
	 */
	public static function admits(string $platformSpec, string $phpSpec, string $server, string $php): ?bool {
		if ($platformSpec === '') {
			return null;
		}
		$runsOnServer = AppStoreSource::satisfiesPlatformSpec($server, $platformSpec);
		if ($runsOnServer !== true || $phpSpec === '') {
			return $runsOnServer;
		}

		return AppStoreSource::satisfiesPhpSpec($php, $phpSpec);
	}

	/**
	 * A spec from an info.xml dependency node's min-version and max-version.
	 */
	private static function specOf(mixed $node): string {
		$attributes = is_array($node) && is_array($node['@attributes'] ?? null) ? $node['@attributes'] : [];
		$bounds = array_filter([
			self::bound('>=', $attributes['min-version'] ?? null),
			self::bound('<=', $attributes['max-version'] ?? null),
		]);

		return implode(' ', $bounds);
	}

	private static function bound(string $operator, mixed $version): string {
		return is_string($version) && trim($version) !== '' ? $operator . trim($version) : '';
	}
}
