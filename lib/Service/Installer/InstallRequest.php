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
 * One install request: its target, its flags and its pin choice.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstallRequest {
	public function __construct(
		public readonly InstallTarget $target,
		public readonly InstallOptions $options,
		public readonly PinChoice $pinChoice,
	) {
	}
}
