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
 * The public surface of PatExpiryEvaluator that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PatExpiryEvaluatorInterface {
	/**
	 * Whole days remaining until `expiresAt` (negative once expired), rounded
	 * up so a partial day still counts as remaining; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function daysRemaining(string $expiresAt): int;

	/**
	 * Highest expiry-warning threshold crossed by a token right now, or null
	 * if none (unknown expiry, or still comfortably valid); see
	 * "PAT expiry warnings" ("Escalation at 3 days and at expiry").
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return self::THRESHOLD_*|null
	 */
	public function highestCrossedThreshold(?string $expiresAt): ?string;
}
