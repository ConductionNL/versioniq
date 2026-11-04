<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Connection;

use OCP\Http\Client\IClientService;

/**
 * The HTTP client of a remote source and the connection report it tells
 * whether the remote answered. The report is optional: without it nothing
 * is reported.
 *
 * @psalm-api
 */
final class ReportedHttp {
	public function __construct(
		public readonly IClientService $clientService,
		public readonly ?ConnectionReportService $connectionReports = null,
	) {
	}
}
