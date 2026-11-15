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

use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\ITempManager;
use RuntimeException;
use ZipArchive;

/**
 * Keeps a restorable backup of an app before a real install replaces it:
 * the old app folder as a zip and the app's own tables as JSON
 * (safety-rollback-backup-and-preflight D3).
 *
 * One app-data folder per backup, named `backup-<createdAt>-<appId>`, holding
 * `app.zip`, `tables.json` and `meta.json`. The folders sit at the top of the
 * app data because only the app-data root can list folders. A backup is kept
 * `backup.keep_days` days (default 14); 0 turns backups off.
 *
 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
 * @psalm-api
 */
class AppBackupStore {
	public const CONFIG_KEEP_DAYS = 'backup.keep_days';
	public const DEFAULT_KEEP_DAYS = 14;

	private const FOLDER_PREFIX = 'backup-';
	private const FOLDER_PATTERN = '/^backup-(\d+)-([a-z][a-z0-9_\-]*)$/D';
	private const ZIP = 'app.zip';
	private const TABLES = 'tables.json';
	private const META = 'meta.json';
	private const DAY = 86400;

	private ?IAppData $appData = null;

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private ITempManager $tempManager,
	) {
	}

	/** Days a backup is kept; 0 or less means no backup is made. */
	public function keepDays(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_KEEP_DAYS, self::DEFAULT_KEEP_DAYS);
	}

	/**
	 * Writes a backup of the app folder and the dumped tables.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @param array<string, list<array<string, mixed>>> $tables
	 * @return array{appId: string, version: string, enabled: string, createdAt: int, keptUntil: int, sizeBytes: int, tables: list<string>}|null The backup, or null when backups are off.
	 * @throws RuntimeException When the backup cannot be written.
	 */
	public function create(string $appId, string $appPath, string $version, string $enabled, array $tables): ?array {
		$keepDays = $this->keepDays();
		if ($keepDays <= 0) {
			return null;
		}
		if (!AppIdFormat::isValid($appId) || !is_dir($appPath)) {
			throw new RuntimeException('There is no app folder to back up.');
		}

		$createdAt = $this->timeFactory->getTime();
		$zip = $this->zipFolder($appPath);
		$tablesJson = json_encode($tables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$meta = [
			'appId' => $appId,
			'version' => $version,
			'enabled' => $enabled,
			'createdAt' => $createdAt,
			'keptUntil' => $createdAt + $keepDays * self::DAY,
			'sizeBytes' => (int)filesize($zip) + strlen($tablesJson),
			'tables' => array_keys($tables),
		];

		$folder = $this->getAppData()->newFolder(self::FOLDER_PREFIX . $createdAt . '-' . $appId);
		try {
			$stream = fopen($zip, 'rb');
			if ($stream === false) {
				throw new RuntimeException('Could not read the app folder backup.');
			}
			$folder->newFile(self::ZIP, $stream);
			$folder->newFile(self::TABLES, $tablesJson);
			$folder->newFile(self::META, json_encode($meta, JSON_THROW_ON_ERROR));
		} catch (\Throwable $error) {
			$folder->delete();
			throw new RuntimeException('Could not write the backup: ' . $error->getMessage(), 0, $error);
		} finally {
			@unlink($zip);
		}

		return $meta;
	}

	/**
	 * The newest backup of the app that is still kept, or null.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
	 * @return array{appId: string, version: string, enabled: string, createdAt: int, keptUntil: int, sizeBytes: int, tables: list<string>}|null
	 */
	public function latest(string $appId): ?array {
		$now = $this->timeFactory->getTime();
		$newest = null;
		foreach ($this->backupFolders() as $entry) {
			if ($entry['appId'] !== $appId || ($newest !== null && $entry['createdAt'] <= $newest['createdAt'])) {
				continue;
			}
			$meta = $this->readMeta($entry['folder']);
			if ($meta !== null && $meta['keptUntil'] >= $now) {
				$newest = $meta;
			}
		}

		return $newest;
	}

	/**
	 * Puts the backed-up app folder back in `$appPath`. The current folder is
	 * moved aside first and moved back when the backup cannot be unpacked.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
	 * @param array{appId: string, createdAt: int} $backup
	 * @throws RuntimeException When the folder cannot be put back.
	 */
	public function restoreFolder(array $backup, string $appPath): void {
		$zipPath = $this->tempManager->getTemporaryFile('.zip');
		if (!is_string($zipPath)) {
			throw new RuntimeException('Could not create a temporary file.');
		}
		try {
			$source = $this->folderOf($backup)->getFile(self::ZIP)->read();
			if (!is_resource($source) || file_put_contents($zipPath, $source) === false) {
				throw new RuntimeException('Could not read the app folder backup.');
			}
			$this->swapIn($zipPath, rtrim($appPath, '/'));
		} finally {
			@unlink($zipPath);
		}
	}

	/**
	 * The dumped tables of a backup.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-restoring-a-broken-install-puts-back-files-and-tables
	 * @param array{appId: string, createdAt: int} $backup
	 * @return array<string, list<array<string, mixed>>>
	 */
	public function tables(array $backup): array {
		$tables = json_decode($this->folderOf($backup)->getFile(self::TABLES)->getContent(), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($tables)) {
			throw new RuntimeException('The table backup is unreadable.');
		}

		/** @var array<string, list<array<string, mixed>>> */
		return $tables;
	}

	/**
	 * Removes every backup whose keep time has passed.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @return int The number of backups removed.
	 */
	public function prune(): int {
		$now = $this->timeFactory->getTime();
		$fallbackUntil = static fn (int $createdAt): int => $createdAt + self::DEFAULT_KEEP_DAYS * self::DAY;
		$removed = 0;
		foreach ($this->backupFolders() as $entry) {
			$meta = $this->readMeta($entry['folder']);
			$keptUntil = $meta['keptUntil'] ?? $fallbackUntil($entry['createdAt']);
			if ($keptUntil < $now) {
				$entry['folder']->delete();
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Zips the folder into a temporary file, entries relative to the folder.
	 */
	private function zipFolder(string $appPath): string {
		$zipPath = $this->tempManager->getTemporaryFile('.zip');
		$zip = new ZipArchive();
		if (!is_string($zipPath) || $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Could not create the app folder backup.');
		}
		$root = rtrim($appPath, '/');
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$relative = substr($item->getPathname(), strlen($root) + 1);
			if ($item->isDir()) {
				$zip->addEmptyDir($relative);
			} elseif ($item->isFile()) {
				$zip->addFile($item->getPathname(), $relative);
			}
		}
		if (!$zip->close()) {
			throw new RuntimeException('Could not create the app folder backup.');
		}

		return $zipPath;
	}

	private function swapIn(string $zipPath, string $appPath): void {
		$staging = $appPath . '.versioniq-restore';
		$aside = $appPath . '.versioniq-restore-old';
		self::removeTree($staging);
		self::removeTree($aside);
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true || !$zip->extractTo($staging)) {
			self::removeTree($staging);
			throw new RuntimeException('Could not unpack the app folder backup.');
		}
		$zip->close();

		if (is_dir($appPath) && !rename($appPath, $aside)) {
			self::removeTree($staging);
			throw new RuntimeException('Could not move the current app folder aside.');
		}
		if (!rename($staging, $appPath)) {
			if (is_dir($aside)) {
				rename($aside, $appPath);
			}
			self::removeTree($staging);
			throw new RuntimeException('Could not put the backed-up app folder in place.');
		}
		self::removeTree($aside);
	}

	/**
	 * @return list<array{folder: ISimpleFolder, createdAt: int, appId: string}>
	 */
	private function backupFolders(): array {
		$folders = [];
		foreach ($this->getAppData()->getDirectoryListing() as $folder) {
			if (preg_match(self::FOLDER_PATTERN, $folder->getName(), $match) === 1) {
				$folders[] = ['folder' => $folder, 'createdAt' => (int)$match[1], 'appId' => $match[2]];
			}
		}

		return $folders;
	}

	/**
	 * @param array{appId: string, createdAt: int} $backup
	 */
	private function folderOf(array $backup): ISimpleFolder {
		return $this->getAppData()->getFolder(self::FOLDER_PREFIX . $backup['createdAt'] . '-' . $backup['appId']);
	}

	/**
	 * @return array{appId: string, version: string, enabled: string, createdAt: int, keptUntil: int, sizeBytes: int, tables: list<string>}|null
	 */
	private function readMeta(ISimpleFolder $folder): ?array {
		try {
			$meta = json_decode($folder->getFile(self::META)->getContent(), true, 16, JSON_THROW_ON_ERROR);
		} catch (NotFoundException|\JsonException) {
			return null;
		}
		if (!is_array($meta) || !is_string($meta['appId'] ?? null) || !is_string($meta['version'] ?? null) || !is_int($meta['createdAt'] ?? null) || !is_int($meta['keptUntil'] ?? null)) {
			return null;
		}

		return [
			'appId' => $meta['appId'],
			'version' => $meta['version'],
			'enabled' => is_string($meta['enabled'] ?? null) ? $meta['enabled'] : 'no',
			'createdAt' => $meta['createdAt'],
			'keptUntil' => $meta['keptUntil'],
			'sizeBytes' => is_int($meta['sizeBytes'] ?? null) ? $meta['sizeBytes'] : 0,
			'tables' => array_values(array_filter((array)($meta['tables'] ?? []), 'is_string')),
		];
	}

	private static function removeTree(string $path): void {
		if (!is_dir($path)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($path);
	}

	private function getAppData(): IAppData {
		if ($this->appData === null) {
			$this->appData = $this->appDataFactory->get(Application::APP_ID);
		}

		return $this->appData;
	}
}
