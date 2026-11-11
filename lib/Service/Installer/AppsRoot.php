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

/**
 * The apps root an install writes into: the first one Nextcloud marks
 * writable, which must also be readable and writable on disk. Shared by the
 * signed (App Store) and the external installer.
 */
final class AppsRoot {
	/**
	 * @throws Exception When the writable root is not usable, or there is none.
	 */
	public static function writablePath(): string {
		foreach (\OC::$APPSROOTS as $dir) {
			if (isset($dir['writable']) && $dir['writable'] === true) {
				if (!is_writable($dir['path']) || !is_readable($dir['path'])) {
					throw new Exception('Cannot write into "apps" directory.');
				}

				return $dir['path'];
			}
		}

		throw new Exception('No writable apps directory found.');
	}
}
