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

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Source\TrustedSourceList;

/**
 * The stored state an install reads and updates: pins, the last known good
 * versions, the artifact cache and the trusted source list.
 *
 * @psalm-api
 */
final class InstallState {
	public function __construct(
		public readonly PinStore $pinStore,
		public readonly LkgStore $lkgStore,
		public readonly ArtifactCache $artifactCache,
		public readonly TrustedSourceList $trustedSources,
		public readonly ForceOverrideStore $forceOverrides,
	) {
	}
}
