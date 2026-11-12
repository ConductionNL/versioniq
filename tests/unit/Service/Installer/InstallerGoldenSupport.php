<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use RuntimeException;

/**
 * Shared helpers for the installer golden tests: a stand-in for the server
 * container behind `OCP\Server::get()`, archive builders and a filesystem
 * snapshot. They run under the unit-only bootstrap, where the server's archive
 * classes are the stubs in tests/stubs/server-internals.php.
 */
final class InstallerGoldenSupport {
	/**
	 * Whether the tests run under the unit-only bootstrap (no Nextcloud server loaded).
	 */
	public static function isUnitOnly(): bool {
		return !class_exists(\OC\Server::class);
	}

	/**
	 * Points `OCP\Server::get()` at the given services.
	 *
	 * @param array<string, object> $services
	 */
	public static function useServices(array $services): void {
		\OC::$server = new class($services) {
			/** @param array<string, object> $services */
			public function __construct(
				private array $services,
			) {
			}

			public function get(string $name): object {
				if (!isset($this->services[$name])) {
					throw new RuntimeException('No test service for ' . $name);
				}

				return $this->services[$name];
			}
		};
	}

	public static function resetServices(): void {
		\OC::$server = null;
	}

	/**
	 * Writes a gzip tarball holding the given files (relative path => contents).
	 *
	 * @param array<string, string> $files
	 */
	public static function tarGz(string $path, array $files): void {
		$tar = substr($path, 0, -3);
		@unlink($tar);
		$phar = new \PharData($tar);
		foreach ($files as $name => $contents) {
			$phar->addFromString($name, $contents);
		}
		$phar->compress(\Phar::GZ);
		unset($phar);
		\PharData::unlinkArchive($tar);
	}

	/**
	 * Writes a zip file holding the given files (relative path => contents).
	 *
	 * @param array<string, string> $files
	 */
	public static function zip(string $path, array $files): void {
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		foreach ($files as $name => $contents) {
			$zip->addFromString($name, $contents);
		}
		$zip->close();
	}

	/**
	 * Every file and folder below `$root`, relative path => contents (null for a folder).
	 *
	 * @return array<string, ?string>
	 */
	public static function snapshot(string $root): array {
		if (!is_dir($root)) {
			return [];
		}
		$tree = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($iterator as $item) {
			/** @var \SplFileInfo $item */
			$relative = substr($item->getPathname(), strlen($root) + 1);
			$tree[$relative] = $item->isDir() ? null : (string)file_get_contents($item->getPathname());
		}
		ksort($tree);

		return $tree;
	}

	public static function removeTree(string $root): void {
		if (!is_dir($root)) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($iterator as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($root);
	}
}
