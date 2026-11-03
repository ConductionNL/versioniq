<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Api;

use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;

/**
 * Who is calling, and the personal access tokens they manage: the caller,
 * the token services, the link that opens a forge's token page, and the
 * connection report that integriq re-reads after a token changes.
 *
 * @psalm-api
 */
final class AccessCollaborators {
	public function __construct(
		public readonly ApiCaller $caller,
		public readonly PatServices $pats,
		public readonly PatDeeplinkBuilder $deeplinkBuilder,
		// Optional: asks integriq to look again after a token save or removal.
		public readonly ?ConnectionReportService $connectionReports = null,
	) {
	}
}
