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
 * The public surface of ReleaseSweepRecorder that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReleaseSweepRecorderInterface {
	/**
	 * The followed apps the sweep adds when they are not installed (releases-new-release-alerts D3).
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-an-admin-follows-the-releases-of-an-app-that-is-not-installed
	 * @return list<array{appId: string, sourceId: ?string, addedBy: string, addedAt: string}>
	 */
	public function followed(): array;

	/**
	 * The stored snapshot, as AvailabilityResultStore::read() gives it.
	 *
	 * @return array{updates: array<string, mixed>, checkedAt: ?int}
	 */
	public function read(): array;

	/**
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
	 * @param array<string, array<string, mixed>> $updates
	 */
	public function record(array $updates, int $checkedAt): void;

	/**
	 * Says the sweep failed, so the check line does not read as checked; the
	 * previous snapshot is kept.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function recordFailure(int $failedAt): void;
}
