<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\InstallProgress;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * install-live-progress task 1.2: both installers report each debug stage to
 * the progress record as it happens, in the debug log's order and names, and
 * without the debug detail (temporary paths, URLs).
 *
 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md
 */
final class InstallerProgressFeedTest extends TestCase {
	private const TOKEN = 'progress-token-for-tests-0001';

	/** @var array<string, mixed> */
	private array $store = [];

	private function progress(): InstallProgress {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key] ?? null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		$progress = new InstallProgress($factory, $this->createMock(ITimeFactory::class), $this->createMock(LoggerInterface::class));
		$progress->start(self::TOKEN);

		return $progress;
	}

	/**
	 * Feed stages through the installer's own private addDebug().
	 *
	 * @param list<string> $stages
	 */
	private static function debug(object $installer, array $stages): void {
		$addDebug = new ReflectionMethod($installer, 'addDebug');
		foreach ($stages as $stage) {
			$addDebug->invoke($installer, $stage, ['tempFile' => '/tmp/secret-path']);
		}
	}

	/**
	 * @param array{stages: list<array<string, mixed>>} $record
	 * @return list<string>
	 */
	private static function names(array $record): array {
		return array_column($record['stages'], 'name');
	}

	public function testASignedInstallReportsItsStagesInOrder(): void {
		$progress = $this->progress();
		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(ArtifactCache::class),
			$progress,
		);
		$stages = ['requested-install', 'release-metadata', 'downloaded', 'archive-extracted', 'info-xml', 'signature-verified', 'migration-diff', 'installed'];

		self::debug($installer, $stages);

		$record = $progress->read(self::TOKEN);
		$this->assertSame($stages, self::names($record));
		$this->assertSame(array_column($installer->getDebugLog(), 'stage'), self::names($record), 'the page shows the debug log\'s names');
		$this->assertSame([[]], array_values(array_unique(array_column($record['stages'], 'detail'), SORT_REGULAR)), 'no debug detail reaches the progress record');
	}

	public function testAnExternalInstallThatFailsItsChecksumStopsAtTheChecksum(): void {
		$progress = $this->progress();
		// Its collaborators are final; only the reporter matters for addDebug().
		$installer = (new ReflectionClass(ExternalReleaseInstallerService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(ExternalReleaseInstallerService::class, 'progress'))->setValue($installer, $progress);

		self::debug($installer, ['requested-install', 'auth-resolution', 'checksum']);
		$progress->finish('failed');

		$record = $progress->read(self::TOKEN);
		$this->assertSame(['requested-install', 'auth-resolution', 'checksum'], self::names($record));
		$this->assertSame('failed', $record['outcome']);
		$this->assertNotNull($record['stages'][2]['endedAt'], 'the failed stage is closed, and no later stage exists');
	}

	public function testWithoutAReporterAnInstallerOnlyLogs(): void {
		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(ArtifactCache::class),
		);

		self::debug($installer, ['requested-install']);

		$this->assertSame(['requested-install'], array_column($installer->getDebugLog(), 'stage'));
	}
}
