<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

require_once __DIR__ . '/FakeSimpleFile.php';
require_once __DIR__ . '/FakeSimpleFolder.php';
require_once __DIR__ . '/FakeAppData.php';

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden test for ArtifactCache's write and verified read: store() over
 * retention, app id and version checks, unreadable archives, every metadata
 * field shape, overwrites, pruning and failing app data; fetch() over every
 * cache entry shape, including the SHA-256 tamper gate. The result, every log
 * line and clock read, and the files (with their content) left behind are
 * compared, case by case as JSON text, with
 * fixtures/artifact-cache-store-fetch.golden.json. Set RECORD_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Cache\ArtifactCache
 */
final class ArtifactCacheStoreFetchGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/artifact-cache-store-fetch.golden.json';

	private const ARCHIVE = 'archive-bytes-v2';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	private string $archivePath = '';

	protected function setUp(): void {
		parent::setUp();
		$this->archivePath = (string)tempnam(sys_get_temp_dir(), 'vq-store-golden-');
		file_put_contents($this->archivePath, self::ARCHIVE);
	}

	protected function tearDown(): void {
		if (is_file($this->archivePath)) {
			unlink($this->archivePath);
		}
		parent::tearDown();
	}

	public function testEveryCaseMatchesTheGolden(): void {
		$actual = [];
		foreach (self::stores() as $name => $case) {
			$actual["store {$name}"] = $this->runStore($case);
		}
		foreach (self::fetches() as $name => $case) {
			$actual["fetch {$name}"] = $this->runFetch($case);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			if (!is_dir(dirname(self::FIXTURE))) {
				mkdir(dirname(self::FIXTURE));
			}
			$lines = [];
			foreach ($actual as $name => $case) {
				$lines[] = json_encode($name) . ': ' . json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'case list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				"case {$name}",
			);
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function stores(): array {
		$sha = hash('sha256', self::ARCHIVE);
		$full = ['sha256' => strtoupper($sha), 'sourceId' => 'github:acme/tool', 'installerKind' => 'external', 'signature' => 'sig', 'certificate' => 'cert'];
		$base = ['appId' => 'someapp', 'version' => '2.0.0', 'meta' => ['sha256' => $sha], 'keep' => 3, 'archive' => 'file', 'existing' => [], 'appData' => 'fake'];
		$list = [];
		$add = static function (string $name, array $changes) use (&$list, $base): void {
			$list[$name] = array_replace($base, $changes);
		};
		$at = static fn (string $time): string => (string)json_encode(['sha256' => 'x', 'cachedAt' => $time]);

		$add('plain', []);
		$add('keep zero', ['keep' => 0]);
		$add('keep negative', ['keep' => -1]);
		$add('invalid app id', ['appId' => 'Some App']);
		$add('invalid version', ['version' => '../2.0.0']);
		$add('long version', ['version' => str_repeat('1', 65)]);
		$add('missing archive', ['archive' => 'missing']);
		$add('all meta fields', ['meta' => $full]);
		$add('no meta', ['meta' => []]);
		$add('non-string meta', ['meta' => ['sha256' => 5, 'sourceId' => 7, 'installerKind' => null, 'signature' => [], 'certificate' => false]]);
		$add('empty meta strings', ['meta' => ['sha256' => '', 'sourceId' => '', 'installerKind' => '', 'signature' => '', 'certificate' => '']]);
		$add('overwrite', ['existing' => ['2.0.0.tar.gz' => 'old', '2.0.0.meta.json' => $at('2025-01-01T00:00:00+00:00')]]);
		$add('prune to keep', ['keep' => 2, 'existing' => ['1.0.0.tar.gz' => 'a', '1.0.0.meta.json' => $at('2026-01-02T00:00:00+00:00'), '0.9.0.tar.gz' => 'b', '0.9.0.meta.json' => $at('2026-01-01T00:00:00+00:00')]]);
		$add('app data fails', ['appData' => 'broken']);

		return $list;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function fetches(): array {
		$sha = hash('sha256', self::ARCHIVE);
		$meta = static fn (mixed $value): string => (string)json_encode($value);
		$base = ['appId' => 'someapp', 'version' => '2.0.0', 'keep' => 3, 'files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => $meta(['sha256' => $sha, 'sourceId' => 'appstore'])], 'appData' => 'fake'];
		$list = [];
		$add = static function (string $name, array $changes) use (&$list, $base): void {
			$list[$name] = array_replace($base, $changes);
		};

		$add('plain', []);
		$add('upper-case digest', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => $meta(['sha256' => strtoupper($sha)])]]);
		$add('keep zero', ['keep' => 0]);
		$add('invalid app id', ['appId' => '1app']);
		$add('invalid app id with files', ['appId' => '1app', 'folder' => 'artifact-cache-1app']);
		$add('invalid version', ['version' => '-2.0.0']);
		$add('invalid version with files', ['version' => '_2.0.0', 'files' => ['_2.0.0.tar.gz' => self::ARCHIVE, '_2.0.0.meta.json' => $meta(['sha256' => $sha])]]);
		$add('no archive, broken meta', ['files' => ['2.0.0.meta.json' => '{']]);
		$add('nothing cached', ['files' => null]);
		$add('no meta file', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE]]);
		$add('no archive file', ['files' => ['2.0.0.meta.json' => $meta(['sha256' => $sha])]]);
		$add('broken meta', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => '{']]);
		$add('deep meta', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => str_repeat('[', 17) . str_repeat(']', 17)]]);
		$add('scalar meta', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => '"x"']]);
		$add('meta without digest', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => $meta(['sourceId' => 'appstore'])]]);
		$add('non-string digest', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => $meta(['sha256' => 5])]]);
		$add('empty digest', ['files' => ['2.0.0.tar.gz' => self::ARCHIVE, '2.0.0.meta.json' => $meta(['sha256' => ''])]]);
		$add('tampered archive', ['files' => ['2.0.0.tar.gz' => 'evil bytes', '2.0.0.meta.json' => $meta(['sha256' => $sha]), '1.0.0.tar.gz' => 'x', '1.0.0.meta.json' => '{}']]);
		$add('app data fails', ['appData' => 'broken']);

		return $list;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['log.warning', $message, str_replace($this->archivePath, '<archive>', (string)json_encode($context, JSON_UNESCAPED_SLASHES))];
		});

		return $logger;
	}

	private function cache(IAppData $appData, int $keep): ArtifactCache {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(function (string $app, string $key, int $default = 0) use ($keep): int {
			$this->calls[] = ['appConfig.int', $app, $key, $default];

			return $keep;
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(function (string $when = 'now', ?\DateTimeZone $zone = null): \DateTime {
			$this->calls[] = ['time.getDateTime', $when, $zone?->getName()];

			return new \DateTime('2026-11-03 12:00:00', $zone);
		});

		return new ArtifactCache($factory, $config, $time, $this->logger());
	}

	private function brokenAppData(): IAppData {
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willThrowException(new RuntimeException('storage offline'));
		$appData->method('newFolder')->willThrowException(new RuntimeException('storage offline'));

		return $appData;
	}

	/**
	 * @param array<string, mixed> $case
	 * @return array<string, mixed>
	 */
	private function runStore(array $case): array {
		$this->calls = [];
		$appData = new FakeAppData();
		if ($case['existing'] !== []) {
			$folder = $appData->newFolder('artifact-cache-someapp');
			foreach ($case['existing'] as $name => $content) {
				$folder->newFile($name, $content);
			}
		}
		$path = $case['archive'] === 'missing' ? $this->archivePath . '.missing' : $this->archivePath;
		$cache = $this->cache($case['appData'] === 'broken' ? $this->brokenAppData() : $appData, $case['keep']);
		set_error_handler(function (int $level, string $message): bool {
			$this->calls[] = ['php.warning', str_replace($this->archivePath, '<archive>', $message)];

			return true;
		}, E_WARNING);
		try {
			$cache->store($case['appId'], $case['version'], $path, $case['meta']);
		} finally {
			restore_error_handler();
		}

		return ['calls' => $this->calls, 'left' => self::contents($appData)];
	}

	/**
	 * @param array<string, mixed> $case
	 * @return array<string, mixed>
	 */
	private function runFetch(array $case): array {
		$this->calls = [];
		$appData = new FakeAppData();
		if ($case['files'] !== null) {
			$folder = $appData->newFolder($case['folder'] ?? 'artifact-cache-someapp');
			foreach ($case['files'] as $name => $content) {
				$folder->newFile($name, $content);
			}
		}
		$cache = $this->cache($case['appData'] === 'broken' ? $this->brokenAppData() : $appData, $case['keep']);
		$result = $cache->fetch($case['appId'], $case['version']);

		return ['result' => $result, 'calls' => $this->calls, 'left' => self::contents($appData)];
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private static function contents(FakeAppData $appData): array {
		$left = [];
		foreach ($appData->getDirectoryListing() as $folder) {
			$files = [];
			foreach ($folder->getDirectoryListing() as $file) {
				$files[$file->getName()] = $file->getContent();
			}
			$left[$folder->getName()] = $files;
		}

		return $left;
	}
}
