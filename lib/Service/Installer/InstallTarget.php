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
 * The app, the version and the optional one-off source of one install request,
 * and the SHA-256 another instance recorded for that version when a promotion
 * installs it (the exact package, not just the version).
 *
 * @spec openspec/specs/version-management/spec.md
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
final class InstallTarget {
	public function __construct(
		public readonly string $appId,
		public readonly string $version,
		public readonly ?string $sourceOverride,
		public readonly ?string $expectedSha = null,
	) {
	}
}
