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

use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;

/**
 * Runs an install: the signed and the external release installer, the
 * environment check before it and the classifier for what went wrong.
 *
 * @psalm-api
 */
final class ReleaseInstallers {
	public function __construct(
		public readonly SelectedReleaseInstallerService $signedInstaller,
		public readonly ExternalReleaseInstallerService $externalInstaller,
		public readonly FailureClassifier $failureClassifier,
		public readonly EnvironmentCheck $environmentCheck,
	) {
	}
}
