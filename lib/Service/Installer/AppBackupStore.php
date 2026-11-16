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
	private const STAGING = '.versioniq-restore';
	private const ASIDE = '.versioniq-restore-old';

	private ?IAppData $appData = null;

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private ITempManager $tempManager,
	) {
	}

	/**
	 * Days a backup is kept; 0 or less means no backup is made.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 */
	public function keepDays(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_KEEP_DAYS, self::DEFAULT_KEEP_DAYS);
	}

	/**
	 * Writes a backup of the app folder and the dumped tables.
	 *
	 * @spec openspec/specs/migration-safety/spec.md#requirement-a-real-install-keeps-a-restorable-backup-of-the-app-folder-and-its-tables
	 * @param BackupSource $source The app, its folder and its state before the install.
	 * @param array<string, list<array<string, mixed>>> $tables
	 * @return array{appId: string, version: string, enabled: string, createdAt: int, keptUntil: int, sizeBytes: int, tables: list<string>}|null The backup, or null when backups are off.
	 * @throws RuntimeException When the backup cannot be written.
	 */
	public function create(BackupSource $source, array $tables): ?array {
		$keepDays = $this->keepDays();
		if ($keepDays <= 0) {
			return null;
		}
		if (!AppIdFormat::isValid($source->appId) || !is_dir($source->appPath)) {
			throw new RuntimeException('There is no app folder to back up.');
		}

		$createdAt = $this->timeFactory->getTime();
		$zip = $this->zipFolder($source->appPath);
		$tablesJson = json_encode($tables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$meta = [
			'appId' => $source->appId,
			'version' => $source->version,
			'enabled' => $source->enabled,
			'createdAt' => $createdAt,
			'keptUntil' => $createdAt + $keepDays * self::DAY,
			'sizeBytes' => (int)filesize($zip) + strlen($tablesJson),
			'tables' => array_keys($tables),
		];
		$files = [self::ZIP => $zip, self::TABLES => $tablesJson, self::META => json_encode($meta, JSON_THROW_ON_ERROR)];
		try {
			$this->writeBackup(name: self::FOLDER_PREFIX . $createdAt . '-' . $source->appId, files: $files);
		} finally {
			@unlink($zip);
		}

		return $meta;
	}

	/**
	 * Write one backup folder: the zip from its temporary path, then the other
	 * named files. A failure removes the folder again.
	 *
	 * @param string $name The backup folder name.
	 * @param array<string, string> $files File name => content; for `app.zip` the temporary zip's path.
	 * @throws RuntimeException When the backup cannot be written.
	 */
	private function writeBackup(string $name, array $files): void {
		$folder = $this->getAppData()->newFolder($name);
		try {
			$folder->newFile(self::ZIP, self::openForReading($files[self::ZIP]));
			foreach (array_diff_key($files, [self::ZIP => true]) as $file => $content) {
				$folder->newFile($file, $content);
			}
		} catch (\Throwable $error) {
			$folder->delete();
			throw new RuntimeException('Could not write the backup: ' . $error->getMessage(), 0, $error);
		}
	}

	/**
	 * @return resource
	 * @throws RuntimeException When the file cannot be opened.
	 */
	private static function openForReading(string $path) {
		$stream = fopen($path, 'rb');
		if ($stream === false) {
			throw new RuntimeException('Could not read the app folder backup.');
		}

		return $stream;
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
		foreach ($this->backupFoldersOf($appId) as $entry) {
			if ($newest === null || $entry['createdAt'] > $newest['createdAt']) {
				$newest = $this->keptMeta(folder: $entry['folder'], now: $now) ?? $newest;
			}
		}

		return $newest;
	}

	/**
	 * The backup folders of one app.
	 *
	 * @return list<array{folder: ISimpleFolder, createdAt: int, appId: string}>
	 */
	private function backupFoldersOf(string $appId): array {
		return array_values(array_filter($this->backupFolders(), static fn (array $entry): bool => $entry['appId'] === $appId));
	}

	/**
	 * The folder's meta while the backup is still kept, else null.
	 *
	 * @return array{appId: string, version: string, enabled: string, createdAt: int, keptUntil: int, sizeBytes: int, tables: list<string>}|null
	 */
	private function keptMeta(ISimpleFolder $folder, int $now): ?array {
		$meta = $this->readMeta($folder);

		return ($meta !== null && $meta['keptUntil'] >= $now) ? $meta : null;
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
		self::addTree(zip: $zip, root: rtrim($appPath, '/'));
		if (!$zip->close()) {
			throw new RuntimeException('Could not create the app folder backup.');
		}

		return $zipPath;
	}

	/**
	 * Add every folder and file under the root to the zip, by its path relative to the root.
	 */
	private static function addTree(ZipArchive $zip, string $root): void {
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
	}

	private function swapIn(string $zipPath, string $appPath): void {
		self::removeTree($appPath . self::STAGING);
		self::removeTree($appPath . self::ASIDE);
		self::unpack(zipPath: $zipPath, staging: $appPath . self::STAGING);
		self::replaceFolder($appPath);
		self::removeTree($appPath . self::ASIDE);
	}

	/**
	 * @throws RuntimeException When the zip cannot be unpacked.
	 */
	private static function unpack(string $zipPath, string $staging): void {
		$zip = new ZipArchive();
		if ($zip->open($zipPath) !== true || !$zip->extractTo($staging)) {
			self::removeTree($staging);
			throw new RuntimeException('Could not unpack the app folder backup.');
		}
		$zip->close();
	}

	/**
	 * Move the current folder aside and the staged one into its place; on failure put the old one back.
	 *
	 * @throws RuntimeException When a folder cannot be moved.
	 */
	private static function replaceFolder(string $appPath): void {
		$staging = $appPath . self::STAGING;
		$aside = $appPath . self::ASIDE;
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
		if (!is_array($meta) || !self::isWellFormed($meta)) {
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

	/**
	 * Whether decoded meta carries the fields every backup has.
	 *
	 * @param array<array-key, mixed> $meta
	 */
	private static function isWellFormed(array $meta): bool {
		return is_string($meta['appId'] ?? null)
			&& is_string($meta['version'] ?? null)
			&& is_int($meta['createdAt'] ?? null)
			&& is_int($meta['keptUntil'] ?? null);
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
		return $this->appData ??= $this->appDataFactory->get(Application::APP_ID);
	}
}
