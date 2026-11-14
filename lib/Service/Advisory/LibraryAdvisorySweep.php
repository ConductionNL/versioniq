<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use OCP\App\IAppManager;

/**
 * One pass over the PHP libraries bundled in every enabled app: scan each
 * app, then ask OSV once for all distinct libraries, within a time budget and
 * a cap on detail calls. It reads only; storing is {@see LibraryAdvisoryCheck}'s.
 *
 * @psalm-api
 */
class LibraryAdvisorySweep {
	/** Wall-clock ceiling for the OSV calls of one run. */
	public const BUDGET_SECONDS = 120.0;

	/** Ceiling on vulnerability detail calls per run. */
	public const MAX_DETAIL_CALLS = 500;

	public function __construct(
		private IAppManager $appManager,
		private BundledLibraryScanner $scanner,
		private OsvClient $osv,
	) {
	}

	/**
	 * The scan of every enabled app and OSV's answer for their libraries.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @return array{scans: array<string, array{manifest: ?string, libraries: list<array{name: string, version: string}>, skipped: int}>, result: array{findings: array<string, list<array<string, mixed>>>, unchecked: list<string>, asked: int, error: ?string}}
	 */
	public function sweep(): array {
		$scans = [];
		$libraries = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			$scans[$appId] = $this->scanner->scan($appId);
			array_push($libraries, ...$scans[$appId]['libraries']);
		}

		$result = $libraries === []
			? ['findings' => [], 'unchecked' => [], 'asked' => 0, 'error' => null]
			: $this->osv->check($libraries, self::BUDGET_SECONDS, self::MAX_DETAIL_CALLS);

		return ['scans' => $scans, 'result' => $result];
	}
}
