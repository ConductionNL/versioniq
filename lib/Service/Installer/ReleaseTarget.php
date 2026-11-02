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
 * Where an extracted release goes: its extracted root, the installed app
 * folder (null for a fresh install) and the destination folder.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class ReleaseTarget {
	public function __construct(
		public readonly string $root,
		public readonly ?string $previousPath,
		public readonly string $destination,
	) {
	}
}
