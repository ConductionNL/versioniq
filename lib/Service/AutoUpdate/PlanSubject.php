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
	/** The minimum age's verdict; null holds nothing back (releases-minimum-age D3). */
	private ?AgeVerdict $age = null;

	/**
	 * @param list<string> $available The versions the app's source offers.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $level,
		public readonly string $installedVersion,
		public readonly array $available,
	) {
	}

	/**
	 * This subject with the minimum age's verdict on its versions.
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	public function withAge(AgeVerdict $age): self {
		$copy = clone $this;
		$copy->age = $age;

		return $copy;
	}

	/**
	 * The advisory ids of a security fix let through before the minimum age
	 * when $version is that fix; [] for any other version.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	public function bypassAdvisoriesFor(string $version): array {
		$age = $this->age;

		return ($age !== null && $age->bypassed !== null && $version === $age->bypassed) ? $age->bypassAdvisories : [];
	}

	/**
	 * The versions a regular (non-security) update may choose from.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/releases-minimum-age
	 */
	public function installable(): array {
		return $this->age?->eligible ?? $this->available;
	}
}
