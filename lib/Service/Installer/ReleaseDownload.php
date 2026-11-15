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

use OCA\Versioniq\Db\Pat;

/**
 * Where one external install downloads and extracts its archive, and the
 * stored token resolved for its source.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ReleaseDownload {
	public function __construct(
		public readonly string $tempFile,
		public readonly string $tempFolder,
		public readonly ?Pat $token,
	) {
	}
}
