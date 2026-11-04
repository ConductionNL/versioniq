<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\Service\InstallerService;
use OCP\App\IAppManager;

/**
 * What an automatic update finds and installs a version with: the installer
 * service lists the available versions, says whether versioniq may manage the
 * app and installs the chosen one; the app manager reports the installed
 * version; the selector chooses the candidate for the app's policy level.
 *
 * @psalm-api
 */
final class AutoUpdateInstall {
	public function __construct(
		public readonly InstallerService $installerService,
		public readonly IAppManager $appManager,
		public readonly CandidateSelector $candidateSelector,
	) {
	}
}
