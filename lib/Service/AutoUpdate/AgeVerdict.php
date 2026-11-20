<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * The minimum age's verdict on one app's versions (releases-minimum-age D3):
 * the in-level versions past the minimum age, an admin's exception and a
 * security fix let through early, and that fix with its advisory ids.
 *
 * @spec openspec/changes/releases-minimum-age
 */
final class AgeVerdict {
	/**
	 * @param list<string> $eligible The versions a regular update may choose from.
	 * @param string|null $bypassed The security fix let through before the minimum age.
	 * @param list<string> $bypassAdvisories The advisory ids behind that fix.
	 */
	public function __construct(
		public readonly array $eligible,
		public readonly ?string $bypassed = null,
		public readonly array $bypassAdvisories = [],
	) {
	}
}
