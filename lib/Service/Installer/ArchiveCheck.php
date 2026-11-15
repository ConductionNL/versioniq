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

/**
 * The integrity outcome of one downloaded archive: its SHA-256, whether it
 * matched the digest recorded on the binding, and the checksum warning.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ArchiveCheck {
	public function __construct(
		public readonly string $sha,
		public readonly bool $recordedShaMatched,
		public readonly ?string $integrityWarning,
	) {
	}
}
