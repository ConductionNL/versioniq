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
 * The public surface of AdvisoryService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdvisoryServiceInterface {
	public const STATE_NONE = 'none';

	public const STATE_AVAILABLE = 'advisory-available';

	/**
	 * Correlates every enabled app, keyed by app id. Individual app failures are
	 * logged and surfaced as an `error` on that app's entry, never thrown, so a
	 * single unreachable source does not abort the whole sweep.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param ?float $budgetSeconds Wall-clock ceiling for the sweep. Callers a
	 *                              user is waiting on should leave this null (see the default). The
	 *                              background refresh passes its own, much larger budget so that moving
	 *                              the work off the request path does not silently shrink what the
	 *                              feature covers.
	 * @return array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string, cveIds?: list<string>, publishedAt?: ?string, url?: ?string}>, recommendedVersion: ?string, error: ?string, history?: list<array{id: string, severity: string, summary: string, cveIds: list<string>, publishedAt: ?string, url: ?string, affectsInstalled: bool}>, fixStatus?: ?string}>
	 */
	public function correlateAll(?float $budgetSeconds = null): array;
}
