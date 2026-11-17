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
 * The public surface of PinDriftHandler that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PinDriftHandlerInterface {
	/**
	 * Compares the live installed version against the app's pin and, on a
	 * newly detected mismatch, records and notifies; see "Drift detection"
	 * and "Drift response — notify and offer re-pin".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function handle(string $appId, string $installedVersion): void;
}
