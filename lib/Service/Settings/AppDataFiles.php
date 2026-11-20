<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Settings;

use OCA\Versioniq\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * One file in Versioniq's app data, addressed as `<folder>/<file>`: read it,
 * or write it, creating the folder and the file when they are missing. Used
 * for the files Versioniq keeps whole, such as the local advisory feed copy
 * and the fetched revocation list.
 *
 * @psalm-api
 */
class AppDataFiles {
	public function __construct(
		private IAppDataFactory $appDataFactory,
	) {
	}

	/**
	 * The content of the file at `<folder>/<file>`.
	 *
	 * @throws NotFoundException when there is no such file
	 * @throws NotPermittedException when it cannot be read
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function read(string $path): string {
		[$folder, $file] = self::split($path);

		return $this->folder($folder)->getFile($file)->getContent();
	}

	/**
	 * Writes the file at `<folder>/<file>`, replacing what it held.
	 *
	 * @throws NotPermittedException when it cannot be written
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function write(string $path, string $content): void {
		[$folderName, $file] = self::split($path);
		$folder = $this->folder($folderName);
		try {
			$folder->getFile($file)->putContent($content);
		} catch (NotFoundException) {
			$folder->newFile($file, $content);
		}
	}

	/**
	 * The folder and the file name of a path.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function split(string $path): array {
		$slash = strrpos($path, '/');

		return $slash === false ? ['', $path] : [substr($path, 0, $slash), substr($path, $slash + 1)];
	}

	private function folder(string $name): ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder($name);
		} catch (NotFoundException) {
			return $appData->newFolder($name);
		}
	}
}
