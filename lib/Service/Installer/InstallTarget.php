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
 * The app, the version and the optional one-off source of one install request.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstallTarget {
	public function __construct(
		public readonly string $appId,
		public readonly string $version,
		public readonly ?string $sourceOverride,
	) {
	}
}
