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
 * The public surface of AdvisoryResultStore that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdvisoryResultStoreInterface {
	/**
	 * Reads the stored snapshot. Returns an empty map with a null `checkedAt`
	 * when no sweep has completed yet — which is a real state on a fresh
	 * install and must be distinguishable from "swept, found nothing".
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array{advisories: array<string, mixed>, checkedAt: ?int, failedAt: ?int, status: ?string, errors: list<string>}
	 */
	public function read(): array;

	/**
	 * Stores a completed correlation snapshot and the moment it completed.
	 *
	 * Only ever called after a sweep finishes, so a half-written snapshot
	 * never replaces a good one.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string, cveIds?: list<string>, publishedAt?: ?string, url?: ?string}>, recommendedVersion: ?string, error: ?string, history?: list<array{id: string, severity: string, summary: string, cveIds: list<string>, publishedAt: ?string, url: ?string, affectsInstalled: bool}>, fixStatus?: ?string}> $correlations
	 */
	public function save(array $correlations, int $checkedAt): void;

	/**
	 * Records a sweep that failed as a whole: the previous snapshot and its
	 * `checkedAt` stay, the outcome says `failed` with the attempt's time as `failedAt`.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function saveFailure(int $failedAt): void;
}
