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
 * One managed, unpinned, installed app as the planner sees it: its level,
 * the version it runs, the versions its source offers and which of them are
 * old enough to install.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 * @spec openspec/changes/releases-minimum-age
 */
final class PlanSubject {
	/**
	 * @param list<string> $available The versions the app's source offers.
	 * @param list<string>|null $eligible The in-level versions past the minimum age, an
	 *                                    admin's exception and a security fix let through
	 *                                    early (releases-minimum-age D3); null holds nothing.
	 * @param string|null $bypassed The security fix let through before the minimum age.
	 * @param list<string> $bypassAdvisories The advisory ids behind that fix.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $level,
		public readonly string $installedVersion,
		public readonly array $available,
		public readonly ?array $eligible = null,
		public readonly ?string $bypassed = null,
		public readonly array $bypassAdvisories = [],
	) {
	}

	/**
	 * The versions a regular (non-security) update may choose from.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	public function installable(): array {
		return $this->eligible ?? $this->available;
	}
}
