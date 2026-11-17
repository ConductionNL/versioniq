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
 * The public surface of AdvisorySettingsStore that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdvisorySettingsStoreInterface {
	/**
	 * Hourly is the floor because the sweep pulls the App Store catalogue and
	 * the GHSA feed; more often than that is sustained upstream traffic for no
	 * practical gain.
	 */
	public const MIN_INTERVAL_HOURS = 1;

	/**
	 * Daily is the ceiling. Beyond it, "your instance is checked for known
	 * vulnerabilities" stops being a claim the feature can honestly make.
	 */
	public const MAX_INTERVAL_HOURS = 24;

	/**
	 * The configured sweep interval in hours, clamped to the supported range.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function getIntervalHours(): int;

	/**
	 * The sweep interval in seconds, for scheduling.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function getIntervalSeconds(): int;
}
