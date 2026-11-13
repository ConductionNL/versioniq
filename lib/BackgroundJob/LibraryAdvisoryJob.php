<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\BundledLibraryScanner;
use OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore;
use OCA\Versioniq\Service\Advisory\OsvClient;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily check of the PHP libraries bundled in every enabled app against OSV.
 *
 * Does nothing while the admin has not switched the check on: it sends
 * library names and versions out. Separate from {@see AdvisoryRefreshJob} so
 * a slow OSV call can never cut the app and server sweep short, and daily
 * because bundled libraries only change when an app is updated.
 *
 * It reads and stores only; it never changes a version.
 *
 * @psalm-api
 */
class LibraryAdvisoryJob extends TimedJob {
	public const INTERVAL_SECONDS = 86400;

	/** Wall-clock ceiling for the OSV calls of one run. */
	public const BUDGET_SECONDS = 120.0;

	/** Ceiling on vulnerability detail calls per run. */
	public const MAX_DETAIL_CALLS = 500;

	public function __construct(
		ITimeFactory $time,
		private AdvisorySettingsStore $settings,
		private IAppManager $appManager,
		private BundledLibraryScanner $scanner,
		private OsvClient $osv,
		private LibraryAdvisoryStore $store,
		private LoggerInterface $logger,
		private ?ConnectionReportService $connectionReports = null,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		if (!$this->settings->isLibraryCheckEnabled()) {
			return;
		}

		try {
			$this->checkAll();
		} catch (\Throwable $error) {
			$this->logger->error('LibraryAdvisoryJob: check failed', ['message' => $error->getMessage()]);
		}
	}

	/**
	 * Scans every enabled app, asks OSV once for all distinct libraries, and
	 * stores the result per app. When OSV could not be asked at all the
	 * previous snapshot is kept: a stale answer, with its age, is worth more
	 * than none.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 */
	public function checkAll(): void {
		$scans = [];
		$libraries = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			$scans[$appId] = $this->scanner->scan($appId);
			array_push($libraries, ...$scans[$appId]['libraries']);
		}

		$result = $libraries === []
			? ['findings' => [], 'unchecked' => [], 'asked' => 0, 'error' => null]
			: $this->osv->check($libraries, self::BUDGET_SECONDS, self::MAX_DETAIL_CALLS);

		$this->connectionReports?->osvChecked($result['asked'], count(array_unique($result['unchecked'])), $result['error']);

		if ($result['error'] !== null && count(array_unique($result['unchecked'])) >= $result['asked']) {
			$this->logger->warning('LibraryAdvisoryJob: OSV could not be asked; keeping the previous snapshot', ['error' => $result['error']]);

			return;
		}

		$this->store->save(self::perApp($scans, $result), $this->time->getTime());
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
