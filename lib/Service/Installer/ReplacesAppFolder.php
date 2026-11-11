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

use Exception;
use OC\Files\FilenameValidator;
use OCP\Server;

/**
 * The filesystem steps both release installers share: move the live app
 * folder aside, copy the extracted release into place, restore the backup
 * after a failure, and clean up. The using class provides `addDebug()`.
 *
 * @spec openspec/specs/version-management/spec.md
 */
trait ReplacesAppFolder {
	abstract private function addDebug(string $stage, mixed $data = null): void;

	/**
	 * Moves the live app folder aside, replacing a stale backup; null when there is no live folder.
	 *
	 * @throws Exception When the live folder cannot be moved aside.
	 */
	private function backupExisting(string $destination): ?string {
		if (!is_dir($destination)) {
			return null;
		}
		$backupDestination = $destination . '.appversion-backup';
		if (is_dir($backupDestination)) {
			$this->rmdirr($backupDestination);
		}
		if (!rename($destination, $backupDestination)) {
			throw new Exception('Could not backup existing app folder before replacement.');
		}

		return $backupDestination;
	}

	/**
	 * Undoes a failed copy: restores the backup, or removes the partially
	 * copied files of a fresh install.
	 */
	private function revertCopy(string $destination, ?string $backupDestination): void {
		if ($backupDestination !== null) {
			$this->restoreFromBackup($destination, $backupDestination);
		} elseif (is_dir($destination)) {
			$this->rmdirr($destination);
		}
	}

	/**
	 * Clears the opcode cache so the new app files load, and logs the swap.
	 */
	private function markFilesystemUpdated(string $destination): void {
		if (function_exists('opcache_reset')) {
			opcache_reset();
		}
		$this->addDebug('filesystem-updated', ['destination' => $destination]);
	}

	/**
	 * Restores the previous app files from the retained backup after a post-swap
	 * failure. Returns whether the restore completed cleanly.
	 */
	private function restoreFromBackup(string $destination, ?string $backupDestination): bool {
		if ($backupDestination === null || !is_dir($backupDestination)) {
			return false;
		}
		try {
			if (is_dir($destination)) {
				$this->rmdirr($destination);
			}

			return rename($backupDestination, $destination);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Finds the single top-level folder inside an extraction path.
	 */
	private function findSingleDirectory(string $path): ?string {
		$entries = scandir($path);
		if (!is_array($entries)) {
			return null;
		}
		$dirs = array_values(array_filter(
			$entries,
			static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path . '/' . $entry)
		));
		if (count($dirs) !== 1) {
			return null;
		}

		return $path . '/' . $dirs[0];
	}

	/**
	 * Recursively copies extracted app files to destination, skipping
	 * forbidden file names.
	 *
	 * @throws Exception
	 */
	private function copyRecursive(string $source, string $destination): void {
		$items = $this->itemsToCopy($source, $destination);

		$filenameValidator = Server::get(FilenameValidator::class);
		foreach ($items as $item) {
			$sourceItem = $source . '/' . $item;
			$destinationItem = $destination . '/' . $item;
			if (is_dir($sourceItem)) {
				$this->copyRecursive($sourceItem, $destinationItem);
			} elseif (is_file($sourceItem) && !$filenameValidator->isForbidden($sourceItem)) {
				$this->copyFile($sourceItem, $destinationItem);
			}
		}
	}

	/**
	 * The entries of an extracted folder, once the source is a folder and the
	 * destination exists.
	 *
	 * @return list<string>
	 * @throws Exception
	 */
	private function itemsToCopy(string $source, string $destination): array {
		if (!is_dir($source)) {
			throw new Exception('Invalid extracted app source folder.');
		}
		if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
			throw new Exception('Could not create destination folder.');
		}
		$items = scandir($source);
		if (!is_array($items)) {
			throw new Exception('Could not read extracted folder contents.');
		}

		return array_values(array_filter($items, static fn (string $item): bool => $item !== '.' && $item !== '..'));
	}

	/**
	 * @throws Exception
	 */
	private function copyFile(string $sourceItem, string $destinationItem): void {
		if (!copy($sourceItem, $destinationItem)) {
			throw new Exception('Could not copy app file "' . basename($sourceItem) . '".');
		}
	}

	/**
	 * Recursively deletes a directory on the local filesystem (temp/backup dirs),
	 * replacing the deprecated \OCP\Files::rmdirr helper.
	 */
	private function rmdirr(string $dir): void {
		if (!is_dir($dir)) {
			if (file_exists($dir) || is_link($dir)) {
				@unlink($dir);
			}

			return;
		}

		/** @var \Iterator<string, \SplFileInfo> $iterator */
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			if ($item->isDir() && !$item->isLink()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
	}
}
