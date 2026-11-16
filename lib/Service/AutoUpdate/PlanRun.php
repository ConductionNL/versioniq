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

use OCA\Versioniq\Service\Policy\PatchPolicy;

/**
 * What one planning run reads once and shares across its apps: the advisory
 * snapshot, whether it is fresh, and the patch policy; and why a security
 * fix was held back, by app id, as the run finds out.
 *
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md
 */
final class PlanRun {
	/** @var array<string, string> */
	private array $holds = [];

	/**
	 * @param array<string, mixed> $rows The advisory snapshot's rows, by app id.
	 */
	public function __construct(
		public readonly array $rows,
		public readonly bool $fresh,
		public readonly PatchPolicy $patchPolicy,
	) {
	}

	/**
	 * The app's advisory row, or null when the snapshot has none.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
	 */
	public function rowOf(string $appId): ?array {
		$row = $this->rows[$appId] ?? null;

		return is_array($row) ? $row : null;
	}

	/**
	 * Records why the app's security fix was held back.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
	 */
	public function hold(string $appId, string $reason): void {
		$this->holds[$appId] = $reason;
	}

	/**
	 * @return array<string, string> Why a fix was held back, by app id.
	 *
	 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
	 */
	public function holds(): array {
		return $this->holds;
	}
}
