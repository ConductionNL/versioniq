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
 * The public surface of AvailabilityResultStore that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityResultStoreInterface {
	/**
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 * @return array{updates: array<string, mixed>, checkedAt: ?int, failedAt: ?int, status: ?string, errors: list<string>}
	 */
	public function read(): array;
}
