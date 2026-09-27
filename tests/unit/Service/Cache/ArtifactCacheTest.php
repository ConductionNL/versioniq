<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

// The Fake* helpers below are not suffixed `*Test.php`, so PHPUnit's
// directory-based test discovery never loads them and there is no
// autoload-dev PSR-4 mapping for the Tests\ namespace (composer.json only
// maps OCA\Versioniq\ -> lib/) — require them explicitly.
require_once __DIR__ . '/FakeSimpleFile.php';
require_once __DIR__ . '/FakeSimpleFolder.php';
require_once __DIR__ . '/FakeAppData.php';

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers "Persist verified artifacts on successful install" (store/prune/
 * non-fatal write failure) and "Cached fallback with full re-verification"
 * (fetch/tamper-discard), plus "Cache visibility and management"
 * (summary/clear/cachedVersionsFor); see design.md "Testing".
 *
 * @spec openspec/specs/artifact-cache/spec.md
 */
final class ArtifactCacheTest extends TestCase {
	private string $tempArchive;

	protected function setUp(): void {
		parent::setUp();
		$this->tempArchive = tempnam(sys_get_temp_dir(), 'artifact-cache-test-');
		file_put_contents($this->tempArchive, 'archive-bytes-v1');
	}

	protected function tearDown(): void {
		if (is_string($this->tempArchive) && file_exists($this->tempArchive)) {
			unlink($this->tempArchive);
		}
		parent::tearDown();
	}

	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	/**
	 * @param array<int, string> $timestamps ISO-8601 timestamps returned by
	 *                                       successive getDateTime() calls (one per store()).
	 */
	private function timeFactory(array $timestamps = ['2026-07-24T00:00:00+00:00']): ITimeFactory {
		$factory = $this->createMock(ITimeFactory::class);
		$dateTimes = array_map(static fn (string $ts): \DateTime => new \DateTime($ts), $timestamps);
		$factory->method('getDateTime')->willReturnOnConsecutiveCalls(...$dateTimes);

		return $factory;
	}

	private function appConfig(int $keep = 3): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')
			->with(Application::APP_ID, 'artifact_cache_keep', 3)
			->willReturn($keep);

		return $config;
	}

	private function build(FakeAppData $appData, int $keep = 3, array $timestamps = ['2026-07-24T00:00:00+00:00']): ArtifactCache {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with(Application::APP_ID)->willReturn($appData);

		return new ArtifactCache($factory, $this->appConfig($keep), $this->timeFactory($timestamps), $this->logger());
	}

	public function testStoreDoesNothingWhenKeepIsZero(): void {
		$appData = new FakeAppData();
		$cache = $this->build($appData, 0);

		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		// No folder was created at all — store() bailed out before touching
		// app data.
		self::assertSame([], $appData->getDirectoryListing());
		self::assertSame([], $cache->cachedVersionsFor('openregister'));
	}

	public function testStoreWritesArchiveAndMetaThenFetchReturnsContent(): void {
		$appData = new FakeAppData();
		$cache = $this->build($appData);

		$cache->store('openregister', '2.3.0', $this->tempArchive, [
			'sha256' => hash('sha256', 'archive-bytes-v1'),
			'sourceId' => 'appstore',
			'installerKind' => 'signed',
			'signature' => 'sig',
			'certificate' => 'cert',
		]);

		$result = $cache->fetch('openregister', '2.3.0');

		self::assertNotNull($result);
		self::assertSame('archive-bytes-v1', $result['content']);
		self::assertSame('appstore', $result['meta']['sourceId']);
		self::assertSame('sig', $result['meta']['signature']);
		self::assertSame('cert', $result['meta']['certificate']);
		self::assertContains('2.3.0', $cache->cachedVersionsFor('openregister'));
	}

	public function testFetchReturnsNullWhenNothingCached(): void {
		$cache = $this->build(new FakeAppData());

		self::assertNull($cache->fetch('openregister', '2.3.0'));
	}

	public function testFetchReturnsNullWhenKeepIsZero(): void {
		// Even if a stale entry exists on disk from a time caching was
		// enabled, keep=0 disables the fallback read path too.
		$appData = new FakeAppData();
		$cache = $this->build($appData, 3);
		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		$disabled = new ArtifactCache(
			$this->factoryFor($appData),
			$this->appConfig(0),
			$this->timeFactory(),
			$this->logger(),
		);

		self::assertNull($disabled->fetch('openregister', '2.3.0'));
	}

	public function testFetchDiscardsAndReturnsNullOnShaMismatch(): void {
		$appData = new FakeAppData();
		$cache = $this->build($appData);

		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		// Tamper with the cached archive directly.
		$appData->getFolder('artifact-cache-openregister')->getFile('2.3.0.tar.gz')->putContent('tampered-bytes');

		self::assertNull($cache->fetch('openregister', '2.3.0'));
		// The tampered entry is discarded, not merely ignored.
		self::assertSame([], $cache->cachedVersionsFor('openregister'));
	}

	public function testRetentionPrunesOldestBeyondKeep(): void {
		$appData = new FakeAppData();
		$timestamps = [
			'2026-07-01T00:00:00+00:00',
			'2026-07-02T00:00:00+00:00',
			'2026-07-03T00:00:00+00:00',
			'2026-07-04T00:00:00+00:00',
		];
		$cache = $this->build($appData, 3, $timestamps);

		$cache->store('openregister', '2.1.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);
		$cache->store('openregister', '2.2.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);
		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);
		$cache->store('openregister', '2.4.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		$cached = $cache->cachedVersionsFor('openregister');
		sort($cached);

		self::assertSame(['2.2.0', '2.3.0', '2.4.0'], $cached);
	}

	public function testStoreFailureIsLoggedAndNonFatal(): void {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willThrowException(new \RuntimeException('app data unavailable'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning');

		$cache = new ArtifactCache($factory, $this->appConfig(3), $this->timeFactory(), $logger);

		// Must not throw — best-effort by construction.
		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);
		self::assertTrue(true);
	}

	public function testSummaryReportsPerAppSizesAndTotal(): void {
		$appData = new FakeAppData();
		$cache = $this->build($appData);
		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		$summary = $cache->summary();

		self::assertSame(3, $summary['keep']);
		self::assertCount(1, $summary['apps']);
		self::assertSame('openregister', $summary['apps'][0]['appId']);
		self::assertSame(['2.3.0'], $summary['apps'][0]['versions']);
		self::assertSame(strlen('archive-bytes-v1'), $summary['apps'][0]['sizeBytes']);
		self::assertSame(strlen('archive-bytes-v1'), $summary['totalSizeBytes']);
	}

	public function testClearRemovesOnlyTheGivenApp(): void {
		$appData = new FakeAppData();
		$timestamps = ['2026-07-01T00:00:00+00:00', '2026-07-02T00:00:00+00:00'];
		$cache = $this->build($appData, 3, $timestamps);
		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);
		$cache->store('opencatalogi', '1.0.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		$cache->clear('openregister');

		self::assertSame([], $cache->cachedVersionsFor('openregister'));
		self::assertContains('1.0.0', $cache->cachedVersionsFor('opencatalogi'));
	}

	public function testClearWithoutAppIdRemovesEverything(): void {
		$appData = new FakeAppData();
		$timestamps = ['2026-07-01T00:00:00+00:00', '2026-07-02T00:00:00+00:00'];
		$cache = $this->build($appData, 3, $timestamps);
		$cache->store('openregister', '2.3.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);
		$cache->store('opencatalogi', '1.0.0', $this->tempArchive, ['sha256' => hash('sha256', 'archive-bytes-v1')]);

		$cache->clear();

		self::assertSame([], $cache->cachedVersionsFor('openregister'));
		self::assertSame([], $cache->cachedVersionsFor('opencatalogi'));
		self::assertSame(['apps' => [], 'totalSizeBytes' => 0, 'keep' => 3], $cache->summary());
	}

	private function factoryFor(FakeAppData $appData): IAppDataFactory {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with(Application::APP_ID)->willReturn($appData);

		return $factory;
	}

	/**
	 * A gzipped tar holding `<top>/appinfo/info.xml`, built by hand so the test
	 * needs no phar support.
	 */
	private function tarGzWithInfoXml(string $infoXml, string $top = 'myapp'): string {
		$entry = static function (string $name, string $body): string {
			$header = str_pad($name, 100, "\0")
				. str_pad('0000644', 8, "\0")
				. str_pad('0000000', 8, "\0")
				. str_pad('0000000', 8, "\0")
				. str_pad(sprintf('%011o', strlen($body)), 12, "\0")
				. str_pad('00000000000', 12, "\0")
				. '        '
				. '0'
				. str_repeat("\0", 100)
				. "ustar\0" . '00';
			$header = str_pad($header, 512, "\0");
			$checksum = array_sum(array_map('ord', str_split($header)));
			$header = substr_replace($header, str_pad(sprintf('%06o', $checksum), 7, "\0") . ' ', 148, 8);

			return $header . str_pad($body, (int)ceil(strlen($body) / 512) * 512, "\0");
		};

		return (string)gzencode(
			$entry($top . '/README.md', 'readme')
			. $entry($top . '/appinfo/info.xml', $infoXml)
			. str_repeat("\0", 1024)
		);
	}

	/**
	 * #435: a forge release showed server compatibility as unknown although
	 * the cached artifact's info.xml states the range.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testPlatformSpecIsReadFromTheCachedArtifactInfoXml(): void {
		file_put_contents($this->tempArchive, $this->tarGzWithInfoXml(
			'<?xml version="1.0"?><info><id>myapp</id><version>1.2.0</version><dependencies><nextcloud min-version="30" max-version="32"/></dependencies></info>'
		));
		$cache = $this->build(new FakeAppData());
		$cache->store('myapp', '1.2.0', $this->tempArchive, []);

		self::assertSame('>=30 <=32', $cache->platformSpecFor('myapp', '1.2.0'));
	}

	/**
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testPlatformSpecWithOnlyAMinimum(): void {
		file_put_contents($this->tempArchive, $this->tarGzWithInfoXml(
			'<?xml version="1.0"?><info><dependencies><nextcloud min-version="31"/></dependencies></info>'
		));
		$cache = $this->build(new FakeAppData());
		$cache->store('myapp', '1.2.0', $this->tempArchive, []);

		self::assertSame('>=31', $cache->platformSpecFor('myapp', '1.2.0'));
	}

	/**
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testPlatformSpecIsNullForAnUncachedVersionOrAnArchiveWithoutInfoXml(): void {
		$cache = $this->build(new FakeAppData());
		self::assertNull($cache->platformSpecFor('myapp', '9.9.9'));

		file_put_contents($this->tempArchive, 'not a tarball');
		$cache->store('myapp', '1.0.0', $this->tempArchive, []);
		self::assertNull($cache->platformSpecFor('myapp', '1.0.0'));
	}
}
