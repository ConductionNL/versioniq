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

use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\ITempManager;

/**
 * What installing an uploaded archive needs besides the install path itself
 * (install-manual-upload D2): the held uploads, the App Store signature
 * check, the unsigned-upload setting and a temporary file to install from.
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.1
 */
final class UploadSupport {
	public function __construct(
		public readonly UploadedArchive $archives,
		public readonly StoreSignatureCheck $signatures,
		public readonly InstanceSettings $settings,
		public readonly ITempManager $tempManager,
	) {
	}
}
