<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

use OCA\Versioniq\BackgroundJob\LibraryAdvisoryJob;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Advisory\BundledLibraryScanner;
use OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore;
use OCA\Versioniq\Service\Advisory\OsvClient;
use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\BackgroundJob\LibraryAdvisoryJob
 * @uses \OCA\Versioniq\Service\Advisory\OsvClient
 */
final class LibraryAdvisoryJobTest extends TestCase {
	private AdvisorySettingsStore&MockObject $settings;
	private BundledLibraryScanner&MockObject $scanner;
	private OsvClient&MockObject $osv;
	private LibraryAdvisoryStore&MockObject $store;
	private ConnectionReportService&MockObject $reports;

	protected function setUp(): void {
		$this->settings = $this->createMock(AdvisorySettingsStore::class);
		$this->scanner = $this->createMock(BundledLibraryScanner::class);
		$this->osv = $this->createMock(OsvClient::class);
		$this->store = $this->createMock(LibraryAdvisoryStore::class);
		$this->reports = $this->createMock(ConnectionReportService::class);
	}

	private function job(): LibraryAdvisoryJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['mail', 'calendar', 'files']);
		$this->scanner->method('scan')->willReturnMap([
			['mail', ['manifest' => 'vendor/composer/installed.json', 'libraries' => [['name' => 'vendor/library', 'version' => '2.4.3'], ['name' => 'vendor/clean', 'version' => '1.0.0']], 'skipped' => 1]],
			['calendar', ['manifest' => null, 'libraries' => [], 'skipped' => 0]],
			['files', ['manifest' => 'vendor/composer/installed.json', 'libraries' => [['name' => 'vendor/library', 'version' => '2.4.3']], 'skipped' => 0]],
		]);

		return new LibraryAdvisoryJob($time, $this->settings, $appManager, $this->scanner, $this->osv, $this->store, $this->createMock(LoggerInterface::class), $this->reports);
	}

	private function runJob(LibraryAdvisoryJob $job): void {
		// run() is protected: the cron runner invokes it.
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testRunsDaily(): void {
		$this->assertSame(86400, $this->job()->getInterval());
	}

	public function testSendsNothingWhileTheSwitchIsOff(): void {
		$this->settings->method('isLibraryCheckEnabled')->willReturn(false);
		$this->scanner->expects($this->never())->method('scan');
		$this->osv->expects($this->never())->method('check');
		$this->store->expects($this->never())->method('save');

		$this->runJob($this->job());
	}

	public function testSavesTheFindingsPerAppWhenTheSwitchIsOn(): void {
		$finding = ['package' => 'vendor/library', 'version' => '2.4.3', 'id' => 'GHSA-aaaa', 'cveIds' => [], 'severity' => 'medium', 'summary' => 's', 'fixedIn' => '2.4.5', 'url' => 'u', 'malicious' => false];
		$this->settings->method('isLibraryCheckEnabled')->willReturn(true);
		$this->osv->expects($this->once())->method('check')
			->with(
				[['name' => 'vendor/library', 'version' => '2.4.3'], ['name' => 'vendor/clean', 'version' => '1.0.0'], ['name' => 'vendor/library', 'version' => '2.4.3']],
				LibraryAdvisoryJob::BUDGET_SECONDS,
				LibraryAdvisoryJob::MAX_DETAIL_CALLS,
			)
			->willReturn(['findings' => ['vendor/library@2.4.3' => [$finding]], 'unchecked' => [], 'asked' => 2, 'error' => null]);
		$this->reports->expects($this->once())->method('osvChecked')->with(2, 0, null);
		$this->store->expects($this->once())->method('save')->with([
			'mail' => ['manifest' => 'vendor/composer/installed.json', 'checked' => 2, 'skipped' => 1, 'error' => null, 'findings' => [$finding]],
			'calendar' => ['manifest' => null, 'checked' => 0, 'skipped' => 0, 'error' => null, 'findings' => []],
			'files' => ['manifest' => 'vendor/composer/installed.json', 'checked' => 1, 'skipped' => 0, 'error' => null, 'findings' => [$finding]],
		], 1700000000);

		$this->runJob($this->job());
	}

	public function testALimitMarksTheAppsWhoseLibrariesWereLeftOut(): void {
		$this->settings->method('isLibraryCheckEnabled')->willReturn(true);
		$this->osv->method('check')->willReturn(['findings' => [], 'unchecked' => ['vendor/clean@1.0.0'], 'asked' => 2, 'error' => 'limit']);
		$this->reports->expects($this->once())->method('osvChecked')->with(2, 1, 'limit');
		$this->store->expects($this->once())->method('save')->with($this->callback(static function (array $saved): bool {
			return $saved['mail']['checked'] === 1
				&& $saved['mail']['error'] === 'Some libraries were not checked this run.'
				&& $saved['files']['error'] === null;
		}), 1700000000);

		$this->runJob($this->job());
	}

	public function testKeepsThePreviousSnapshotWhenOsvFails(): void {
		$this->settings->method('isLibraryCheckEnabled')->willReturn(true);
		$this->osv->method('check')->willReturn(['findings' => [], 'unchecked' => ['vendor/library@2.4.3', 'vendor/clean@1.0.0'], 'asked' => 2, 'error' => 'OSV returned HTTP 503.']);
		$this->reports->expects($this->once())->method('osvChecked')->with(2, 2, 'OSV returned HTTP 503.');
		$this->store->expects($this->never())->method('save');

		$this->runJob($this->job());
	}
}
