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
 * One app going from one version to another; a null version is an app that
 * appeared or went away.
 *
 * @psalm-api
 */
final class AppVersionChange {
	public function __construct(
		public readonly string $appId,
		public readonly ?string $fromVersion,
		public readonly ?string $toVersion,
	) {
	}
}
