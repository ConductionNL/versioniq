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
 * The public surface of AttemptLedger that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttemptLedgerInterface {
	public const OUTCOME_SUCCESS = 'success';

	public const OUTCOME_FAILURE = 'failure';

	/**
	 * Records the outcome of an attempted (appId, version) install, pruning
	 * to the most recent entries; see "Nightly
	 * policy execution through the standard installer".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @param self::OUTCOME_* $outcome
	 */
	public function record(string $appId, string $version, string $outcome, string $at): void;
}
