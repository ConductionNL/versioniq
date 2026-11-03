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
 * Who did what to which app, for one audit entry.
 *
 * @psalm-api
 */
final class AuditSubject {
	public function __construct(
		public readonly string $actorUid,
		public readonly string $appId,
		public readonly string $operation,
	) {
	}
}
