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
 * An uploaded archive that passed the install guards: the admin's request,
 * the run it installs under and the SHA-256 the admin was shown.
 */
final class PendingUpload {
	/**
	 * @param UploadInstallRequest $ask What the admin asked.
	 * @param InstallRun $run The run the archive installs under.
	 * @param string $sha256 The SHA-256 the inspect step showed.
	 */
	public function __construct(
		public readonly UploadInstallRequest $ask,
		public readonly InstallRun $run,
		public readonly string $sha256,
	) {
	}
}
