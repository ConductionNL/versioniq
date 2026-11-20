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
	/**
	 * The kinds of regular update the schedule lets run (autoupdate-schedules D4); every kind by default.
	 *
	 * @var list<string>
	 */
	private array $dueKinds = UpdateType::ALL;

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
	 * This run, letting only the given kinds of regular update install.
	 *
	 * @param list<string> $dueKinds
	 *
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public function withDueKinds(array $dueKinds): self {
		$run = clone $this;
		$run->dueKinds = $dueKinds;

		return $run;
	}

	/**
	 * Whether the step from `$installedVersion` to `$candidate` is a kind the schedule lets run.
	 *
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public function isDue(string $installedVersion, string $candidate): bool {
		return in_array(UpdateType::classify($installedVersion, $candidate), $this->dueKinds, true);
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
