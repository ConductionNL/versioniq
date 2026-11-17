<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

/**
 * Who made a change and what triggered it, as written on its history row.
 *
 * @psalm-api
 */
final class ChangeOrigin {
	public function __construct(
		public readonly string $trigger,
		public readonly string $actor,
	) {
	}

	/**
	 * A change the hourly comparison found: no event reported it.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public static function hourly(): self {
		return new self(ExternalChangeRecorder::HOURLY_TRIGGER, ExternalChangeRecorder::SYSTEM);
	}
}
