<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Symfony's HeaderUtils, which OCP's DownloadResponse constructor calls.
 *
 * Inside Nextcloud the server ships symfony/http-foundation; the unit-only run
 * does not, so building a DataDownloadResponse there threw "Class HeaderUtils
 * not found". Loaded by bootstrap-unit-only.php only when the real class is
 * absent, and covering only what that constructor uses.
 */

namespace Symfony\Component\HttpFoundation {
	final class HeaderUtils {
		public const DISPOSITION_ATTACHMENT = 'attachment';
		public const DISPOSITION_INLINE = 'inline';

		public static function makeDisposition(string $disposition, string $filename, string $filenameFallback = ''): string {
			$params = ['filename' => ($filenameFallback !== '' ? $filenameFallback : $filename)];
			if ($filename !== $params['filename']) {
				$params['filename*'] = "utf-8''" . rawurlencode($filename);
			}

			$parts = [$disposition];
			foreach ($params as $name => $value) {
				$parts[] = $name . '="' . addcslashes($value, '"\\') . '"';
			}

			return implode('; ', $parts);
		}
	}
}
