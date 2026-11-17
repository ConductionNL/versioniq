<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Kernel\Contracts;

/**
 * The public surface of AvailabilityService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityServiceInterface {
	/**
	 * Sweeps every installed, manageable app within the given wall-clock budget.
	 *
	 * An app reached after the budget ran out is recorded with an error rather
	 * than left out, so the page says "not checked" instead of nothing.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @return array<string, array{installedVersion: string, newestVersion: ?string, newestCompatibleVersion: ?string, linesBehind: int, updateAvailable: bool, pinned: bool, sourceId: ?string, error: ?string, installedWithdrawn: ?bool, lifecycle?: array<string, mixed>}>
	 * @param list<array{appId: string, sourceId: ?string}> $followed Followed apps that join the sweep when not installed (releases-new-release-alerts D3).
	 */
	public function sweep(float $budgetSeconds, array $followed = []): array;
}
