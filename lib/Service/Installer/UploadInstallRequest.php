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
 * What an admin asked when installing an uploaded archive (install-manual-upload D2).
 */
final class UploadInstallRequest {
	/**
	 * @param string $token The token the inspect step returned.
	 * @param ?string $expectedSha256 The SHA-256 the admin expects; blank or null when none was pasted.
	 * @param bool $acceptShown Whether the admin accepted the SHA-256 shown for an unsigned archive.
	 * @param bool $allowDowngrade Acknowledges a downgrade, as on the normal install path.
	 * @param ?string $overridePin `repin` or `unpin` to install over a pin, as on the normal install path.
	 */
	public function __construct(
		public readonly string $token,
		public readonly ?string $expectedSha256,
		public readonly bool $acceptShown,
		public readonly bool $allowDowngrade = false,
		public readonly ?string $overridePin = null,
	) {
	}

	/**
	 * The pasted SHA-256, trimmed and lower-cased; empty when none was pasted.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.1
	 */
	public function expectedSha(): string {
		return strtolower(trim($this->expectedSha256 ?? ''));
	}
}
