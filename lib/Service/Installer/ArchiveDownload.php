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
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;

/**
 * Fetches a release archive: the HTTP client, the temporary file it lands
 * in, the artifact cache that may already hold it, and the forge registry
 * that says which forge a download URL belongs to.
 *
 * @psalm-api
 */
final class ArchiveDownload {
	public function __construct(
		public readonly IClientService $clientService,
		public readonly ITempManager $tempManager,
		public readonly ArtifactCache $artifactCache,
		public readonly ForgeRegistry $forgeRegistry,
	) {
	}
}
