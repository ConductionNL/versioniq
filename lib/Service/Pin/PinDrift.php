<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pin;

/**
 * A pinned app observed at another version: the version it drifted to and
 * when that was seen (ISO 8601).
 *
 * @spec openspec/specs/version-pinning/spec.md
 */
final class PinDrift {
	public function __construct(
		public readonly string $version,
		public readonly string $observedAt,
	) {
	}
}
