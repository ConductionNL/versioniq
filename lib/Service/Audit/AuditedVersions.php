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
 * The versions an audited change moved between and the source it came
 * from. Each may be null; an empty string is stored as null too.
 *
 * @psalm-api
 */
final class AuditedVersions {
	public function __construct(
		public readonly ?string $fromVersion,
		public readonly ?string $toVersion,
		public readonly ?string $sourceId,
	) {
	}
}
