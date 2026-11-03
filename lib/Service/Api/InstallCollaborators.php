<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Api;

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\InstallerService;
use OCP\App\IAppManager;
use OCP\ServerVersion;

/**
 * Lists, binds and installs app versions: the installer, the running server
 * version, the app manager and the artifact cache.
 *
 * @psalm-api
 */
final class InstallCollaborators {
	public function __construct(
		public readonly InstallerService $installerService,
		public readonly ServerVersion $serverVersion,
		public readonly IAppManager $appManager,
		public readonly ArtifactCache $artifactCache,
	) {
	}
}
