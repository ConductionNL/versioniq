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

use OCA\Versioniq\Service\Connection\ConnectionReportService;
use Psr\Log\LoggerInterface;

/**
 * Runs a {@see LibraryAdvisorySweep}, reports the OSV connection, and stores
 * the result per app. When OSV could not be asked at all the previous
 * snapshot is kept: a stale answer, with its age, is worth more than none.
 *
 * @psalm-api
 */
class LibraryAdvisoryCheck {
	public function __construct(
		private LibraryAdvisorySweep $sweep,
		private LibraryAdvisoryStore $store,
		private LoggerInterface $logger,
		private ?ConnectionReportService $connectionReports = null,
	) {
	}

	/**
	 * Check every enabled app's libraries and store the result.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @param int $now The time the check completes, stored as `checkedAt`.
	 */
	public function checkAll(int $now): void {
		['scans' => $scans, 'result' => $result] = $this->sweep->sweep();

		$this->connectionReports?->osvChecked($result['asked'], count(array_unique($result['unchecked'])), $result['error']);

		if ($result['error'] !== null && count(array_unique($result['unchecked'])) >= $result['asked']) {
			$this->logger->warning('LibraryAdvisoryJob: OSV could not be asked; keeping the previous snapshot', ['error' => $result['error']]);

			return;
		}

		$this->store->save(self::perApp($scans, $result), $now);
	}

	/**
	 * The snapshot entry of every app from its scan and the OSV answer.
	 *
	 * @param array<string, array{manifest: ?string, libraries: list<array{name: string, version: string}>, skipped: int}> $scans
	 * @param array{findings: array<string, list<array<string, mixed>>>, unchecked: list<string>, asked: int, error: ?string} $result
	 * @return array<string, array{manifest: ?string, checked: int, skipped: int, error: ?string, findings: list<array<string, mixed>>}>
	 */
	private static function perApp(array $scans, array $result): array {
		$unchecked = array_flip($result['unchecked']);
		$perApp = [];
		foreach ($scans as $appId => $scan) {
			$findings = [];
			$missed = 0;
			foreach ($scan['libraries'] as $library) {
				$key = OsvClient::key($library);
				if (isset($unchecked[$key])) {
					$missed++;
					continue;
				}
				array_push($findings, ...($result['findings'][$key] ?? []));
			}
			$perApp[$appId] = [
				'manifest' => $scan['manifest'],
				'checked' => count($scan['libraries']) - $missed,
				'skipped' => $scan['skipped'],
				'error' => $missed > 0 ? 'Some libraries were not checked this run.' : null,
				'findings' => $findings,
			];
		}

		return $perApp;
	}
}
