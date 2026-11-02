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
 * An extracted and validated release: its extracted root, its app info, the
 * installed app folder (null for a fresh install) and the folder it goes to.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class StagedRelease {
	/**
	 * @param array<string, mixed> $info
	 */
	public function __construct(
		public readonly string $root,
		public readonly array $info,
		public readonly ?string $previousPath,
		public readonly string $destination,
	) {
	}
}
