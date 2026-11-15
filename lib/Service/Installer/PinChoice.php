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
 * What an install request asks for the app's pin: an override of an existing
 * pin (`null`, `repin` or `unpin`) and whether to pin the installed version.
 *
 * @spec openspec/specs/version-pinning/spec.md
 */
final class PinChoice {
	public function __construct(
		public readonly ?string $overridePin,
		public readonly bool $pinRequested,
	) {
	}
}
