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
 * The public surface of PinStore that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PinStoreInterface {
	/**
	 * Returns every persisted pin, keyed by app id; see "Honest pin presentation".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @return array<string, \OCA\Versioniq\Service\Pin\Pin>
	 */
	public function all(): array;
}
