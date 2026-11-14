<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use OCA\Versioniq\AppInfo\Application;
use Closure;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\L10N\IFactory;

/**
 * Detects environment conditions that prevent a successful install.
 *
 * The authoritative, blocking check is whether the PARENT directory of an
 * app's folder is writable — `rename()` of the existing folder (the backup
 * step) needs write permission on the parent, not on the folder itself.
 * Dev-checkout heuristics (a `.git` directory, owner ≠ web-server uid) only
 * enrich the human warning and never block on their own.
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class EnvironmentCheck {
	/**
	 * The package size assumed when the release does not say (design D2).
	 */
	public const DEFAULT_PACKAGE_BYTES = 52428800;

	/**
	 * Free space needed in the apps folder per package byte: the old copy
	 * moved aside, the new copy and the backup (design D2).
	 */
	public const APPS_FACTOR = 3;

	/**
	 * Free space needed in the temp folder per package byte: the download
	 * and its extraction (design D2).
	 */
	public const TEMP_FACTOR = 2;

	/** @var Closure(string): (float|false) */
	private Closure $freeSpace;

	/**
	 * @param ITempManager|null $tempManager Where downloads land; the system temp folder when null.
	 * @param Closure|null      $freeSpace   Free bytes of a folder, or false when unknown; disk_free_space() when null.
	 */
	public function __construct(
		private IFactory $l10nFactory,
		private ?ITempManager $tempManager = null,
		?Closure $freeSpace = null,
	) {
		$this->freeSpace = $freeSpace ?? static fn (string $dir): float|false => @disk_free_space($dir);
	}

	/**
	 * The app's translator. Each inspect() translates at most one string, and
	 * the factory keeps one instance per app and language, so this class holds
	 * no copy of its own.
	 */
	private function l10n(): IL10N {
		return $this->l10nFactory->get(Application::APP_ID);
	}

	/**
	 * Authoritative functional check used by the fail-fast guard: can the
	 * web-server user rename the app folder (i.e. is its parent writable)?
	 */
	public function isDestinationWritable(string $appPath): bool {
		return is_writable(dirname($appPath));
	}

	/**
	 * Inspects an installed app's folder and returns a card descriptor.
	 *
	 * @return array{manageable: bool, warning: ?string}
	 */
	public function inspect(string $appPath): array {
		$writable = $this->isDestinationWritable($appPath);

		if (!$writable) {
			return [
				'manageable' => false,
				'warning' => $this->l10n()->t('This app\'s folder is not writable by the web-server user, so version changes will fail. It is likely a bind-mounted dev checkout — fix the folder ownership/permissions to manage it here.'),
			];
		}

		// Writable: manageable. Advisory dev-checkout signals enrich the warning
		// but never flip `manageable` to false.
		$advisory = $this->devCheckoutWarning($appPath);

		return [
			'manageable' => true,
			'warning' => $advisory,
		];
	}

	/**
	 * Advisory-only warning for a writable folder that still looks like a dev
	 * checkout (contains `.git`, or is owned by a different uid than the
	 * web-server process). Returns null when no heuristic matches.
	 */
	private function devCheckoutWarning(string $appPath): ?string {
		if (!$this->looksLikeDevCheckout($appPath)) {
			return null;
		}

		return $this->l10n()->t('This app looks like a development checkout (e.g. a Git working copy or a bind mount owned by another user). Version changes here will overwrite your working copy.');
	}

	/**
	 * Whether either dev-checkout signal matches: a `.git` directory, or an
	 * owner other than the web-server process. Both signals are read.
	 */
	private function looksLikeDevCheckout(string $appPath): bool {
		$hasGitDir = is_dir($appPath . '/.git');
		$foreignOwner = $this->isOwnedByAnotherUser($appPath);

		return $hasGitDir || $foreignOwner;
	}

	/**
	 * Whether the folder's owner differs from the web-server process; false
	 * when posix is unavailable or the owner cannot be read.
	 */
	private function isOwnedByAnotherUser(string $appPath): bool {
		if (!function_exists('posix_getuid')) {
			return false;
		}
		$owner = @fileowner($appPath);

		return $owner !== false && $owner !== posix_getuid();
	}

	/**
	 * Whether the apps folder and the temp folder have room for a package:
	 * three times its size next to the app, two times in temp. A folder
	 * whose free space cannot be read is not held against the install.
	 *
	 * @param string|null $appPath      The installed app's folder; null for a fresh install, which checks temp only.
	 * @param int|null    $packageBytes The package size; DEFAULT_PACKAGE_BYTES when unknown.
	 *
	 * @return array{fits: bool, appsFree: ?int, appsNeeded: int, tempFree: ?int, tempNeeded: int}
	 *
	 * @spec openspec/changes/safety-rollback-backup-and-preflight/specs/migration-safety/spec.md
	 */
	public function hasSpaceFor(?string $appPath, ?int $packageBytes = null): array {
		$bytes = ($packageBytes !== null && $packageBytes > 0) ? $packageBytes : self::DEFAULT_PACKAGE_BYTES;
		$appsFree = $appPath !== null ? $this->freeBytes(dirname($appPath)) : null;
		$tempFree = $this->freeBytes($this->tempDir());
		$appsNeeded = $bytes * self::APPS_FACTOR;
		$tempNeeded = $bytes * self::TEMP_FACTOR;

		return [
			'fits' => ($appsFree === null || $appsFree >= $appsNeeded) && ($tempFree === null || $tempFree >= $tempNeeded),
			'appsFree' => $appsFree,
			'appsNeeded' => $appsNeeded,
			'tempFree' => $tempFree,
			'tempNeeded' => $tempNeeded,
		];
	}

	private function freeBytes(string $dir): ?int {
		$free = ($this->freeSpace)($dir);

		return $free === false ? null : (int)$free;
	}

	private function tempDir(): string {
		return $this->tempManager?->getTempBaseDir() ?? sys_get_temp_dir();
	}
}
