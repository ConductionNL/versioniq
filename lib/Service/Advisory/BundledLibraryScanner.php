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

use OCP\App\IAppManager;
use Throwable;

/**
 * Reads the PHP libraries an installed app bundles, from the manifest Composer
 * writes into the `vendor/` directory it installs.
 *
 * The manifest is the proof that the libraries ship: a `composer.lock`
 * without a `vendor/` directory says what a developer would install, not what
 * is on disk, so it is never read. An app without the manifest is reported as
 * such (`manifest: null`) so the page can say its libraries were not checked
 * instead of implying they were clean.
 *
 * @psalm-api
 */
class BundledLibraryScanner {
	/** The manifest's path inside an app directory. */
	public const MANIFEST = 'vendor/composer/installed.json';

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/**
	 * The libraries the app bundles, and how many listed packages were left
	 * out (development-only packages and branch versions OSV cannot match).
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @return array{manifest: ?string, libraries: list<array{name: string, version: string}>, skipped: int}
	 */
	public function scan(string $appId): array {
		$none = ['manifest' => null, 'libraries' => [], 'skipped' => 0];

		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (Throwable) {
			return $none;
		}

		$file = rtrim($appPath, '/') . '/' . self::MANIFEST;
		if (!is_file($file) || !is_readable($file)) {
			return $none;
		}

		$raw = file_get_contents($file);
		if ($raw === false) {
			return $none;
		}

		return ['manifest' => self::MANIFEST] + $this->parse($raw);
	}

	/**
	 * The libraries in one manifest body. Accepts the Composer 2 layout (an
	 * object with `packages` and `dev-package-names`) and the Composer 1
	 * layout (a plain list of packages). An unreadable body lists nothing.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @return array{libraries: list<array{name: string, version: string}>, skipped: int}
	 */
	public function parse(string $raw): array {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return ['libraries' => [], 'skipped' => 0];
		}
		if (!is_array($decoded)) {
			return ['libraries' => [], 'skipped' => 0];
		}

		/** @var mixed $packages */
		$packages = array_is_list($decoded) ? $decoded : ($decoded['packages'] ?? []);
		/** @var mixed $devNames */
		$devNames = array_is_list($decoded) ? [] : ($decoded['dev-package-names'] ?? []);
		if (!is_array($packages)) {
			return ['libraries' => [], 'skipped' => 0];
		}
		/** @var list<string> $devList */
		$devList = is_array($devNames) ? array_values(array_filter($devNames, is_string(...))) : [];

		return $this->collect($packages, array_fill_keys($devList, true));
	}

	/**
	 * @param array<array-key, mixed> $packages
	 * @param array<string, true> $devNames
	 * @return array{libraries: list<array{name: string, version: string}>, skipped: int}
	 */
	private function collect(array $packages, array $devNames): array {
		$libraries = [];
		$skipped = 0;
		/** @var array<array-key, mixed> $package */
		foreach (array_filter($packages, is_array(...)) as $package) {
			$name = self::text($package, 'name');
			$version = self::text($package, 'version');
			if ($name === null || $version === null) {
				continue;
			}
			// A branch version names no release OSV can match, and a
			// development-only package does not ship to the instance.
			if (isset($devNames[$name]) || str_starts_with($version, 'dev-')) {
				$skipped++;
				continue;
			}
			$libraries[] = ['name' => $name, 'version' => self::plainVersion($version)];
		}

		return ['libraries' => $libraries, 'skipped' => $skipped];
	}

	/**
	 * A string field of a decoded package, or null when it is not a string.
	 *
	 * @param array<array-key, mixed> $package
	 */
	private static function text(array $package, string $field): ?string {
		/** @var mixed $value */
		$value = $package[$field] ?? null;

		return is_string($value) ? $value : null;
	}

	/**
	 * The version without the leading `v` tags often carry.
	 */
	private static function plainVersion(string $version): string {
		return (str_starts_with($version, 'v') || str_starts_with($version, 'V')) ? substr($version, 1) : $version;
	}
}
