<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

require_once __DIR__ . '/FakeSimpleFile.php';
require_once __DIR__ . '/FakeSimpleFolder.php';
require_once __DIR__ . '/FakeAppData.php';

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden test for ArtifactCache's read and housekeeping paths:
 * platformSpecFor() over cached archives of every layout (gzipped or plain
 * tar, top level or one folder down, ustar prefixes, padding, truncation,
 * broken sizes and gzip) and every info.xml range, clear() over one app, all
 * apps and failing folders, and prune() over every retention shape. The
 * result, every log line and the files left behind are compared, case by case
 * as JSON text, with fixtures/artifact-cache-read.golden.json. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Cache\ArtifactCache
 * @uses \OCA\Versioniq\Service\Installer\AppIdFormat
 */
final class ArtifactCacheReadGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/artifact-cache-read.golden.json';

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	public function testEveryCaseMatchesTheGolden(): void {
		$actual = [];
		foreach (self::archives() as $name => $archive) {
			$actual["spec {$name}"] = $this->runSpec($archive);
		}
		$actual['spec not cached'] = $this->runSpec(null);
		$actual['spec keep zero'] = $this->runSpec(self::tar(['appinfo/info.xml' => self::info('30', '32')]), 0);
		$actual['spec tampered'] = $this->runSpec(self::tar(['appinfo/info.xml' => self::info('30', '32')]), 3, 'deadbeef');
		$actual['spec invalid app id'] = $this->runSpec(self::tar(['appinfo/info.xml' => self::info('30', '32')]), 3, null, 'Bad App');
		foreach (self::clears() as $name => [$appId, $broken]) {
			$actual["clear {$name}"] = $this->runClear($appId, $broken);
		}
		foreach (self::prunes() as $name => [$metas, $keep]) {
			$actual["prune {$name}"] = $this->runPrune($metas, $keep);
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
	 * An archive that decompresses far past the 256 MiB limit is not read, even
	 * when its info.xml comes first and an unlimited read would find it.
	 */
	public function testAnArchivePastTheDecompressionLimitIsNotRead(): void {
		$tar = self::tar(['appinfo/info.xml' => self::info('30', '32')]);
		// gzdecode() can hold about twice the limit before it gives up.
		$memoryLimit = (string)ini_get('memory_limit');
		ini_set('memory_limit', '1200M');

		try {
			self::assertSame('>=30 <=32', $this->runSpec(self::gzipWithPadding($tar, 1))['result']);
			self::assertNull($this->runSpec(self::gzipWithPadding($tar, 400))['result']);
		} finally {
			ini_set('memory_limit', $memoryLimit);
		}
	}

	/**
	 * The gzipped tar followed by the given MiB of zero bytes.
	 */
	private static function gzipWithPadding(string $tar, int $mebibytes): string {
		$context = deflate_init(ZLIB_ENCODING_GZIP);
		$out = (string)deflate_add($context, $tar, ZLIB_NO_FLUSH);
		$chunk = str_repeat("\0", 1048576);
		for ($i = 0; $i < $mebibytes; $i++) {
			$out .= (string)deflate_add($context, $chunk, ZLIB_NO_FLUSH);
		}

		return $out . (string)deflate_add($context, '', ZLIB_FINISH);
	}

	private static function info(?string $min, ?string $max): string {
		$attrs = ($min === null ? '' : ' min-version="' . $min . '"') . ($max === null ? '' : ' max-version="' . $max . '"');

		return '<?xml version="1.0"?><info><id>someapp</id><dependencies><nextcloud' . $attrs . '/></dependencies></info>';
	}

	/**
	 * A tar archive of the given entries; a name may carry a ustar prefix as
	 * `prefix|name`, and a `size` override as `name#size`.
	 *
	 * @param array<string, string> $entries
	 */
	private static function tar(array $entries, bool $ustar = true, bool $end = true): string {
		$out = '';
		foreach ($entries as $name => $content) {
			$size = sprintf('%011o', strlen($content));
			if (str_contains($name, '#')) {
				[$name, $size] = explode('#', $name, 2);
			}
			$prefix = '';
			if (str_contains($name, '|')) {
				[$prefix, $name] = explode('|', $name, 2);
			}
			$header = str_pad($name, 100, "\0") . str_repeat("\0", 24) . str_pad($size, 12, "\0");
			$header = str_pad($header, 257, "\0") . ($ustar ? "ustar\0" : str_repeat("\0", 6));
			$header = str_pad($header, 345, "\0") . str_pad($prefix, 155, "\0");
			$out .= str_pad($header, 512, "\0") . $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
		}

		return $out . ($end ? str_repeat("\0", 1024) : '');
	}

	/**
	 * @return array<string, string>
	 */
	private static function archives(): array {
		$info = self::info('30', '32');

		return [
			'gz top level' => (string)gzencode(self::tar(['appinfo/info.xml' => $info])),
			'plain top level' => self::tar(['appinfo/info.xml' => $info]),
			'one folder down' => self::tar(['someapp/README.md' => str_repeat('r', 700), 'someapp/appinfo/info.xml' => $info]),
			'dot slash' => self::tar(['./appinfo/info.xml' => $info]),
			'dot slash folder' => self::tar(['./someapp/appinfo/info.xml' => $info]),
			'two folders down' => self::tar(['a/b/appinfo/info.xml' => $info]),
			'ustar prefix' => self::tar(['someapp|appinfo/info.xml' => $info]),
			'ustar prefix too deep' => self::tar(['a/b|appinfo/info.xml' => $info]),
			'prefix without ustar' => self::tar(['someapp|appinfo/info.xml' => $info], false),
			'no info xml' => self::tar(['someapp/appinfo/routes.php' => '<?php']),
			'info xml dir lookalike' => self::tar(['appinfo/info.xml.bak' => $info, 'appinfo/info.xml' => self::info('31', null)]),
			'empty info xml' => self::tar(['appinfo/info.xml' => '']),
			'broken xml' => self::tar(['appinfo/info.xml' => '<info><dependencies>']),
			'no nextcloud element' => self::tar(['appinfo/info.xml' => '<info><dependencies/></info>']),
			'min only' => self::tar(['appinfo/info.xml' => self::info('30', null)]),
			'max only' => self::tar(['appinfo/info.xml' => self::info(null, '33')]),
			'dotted versions' => self::tar(['appinfo/info.xml' => self::info('30.0.1', '32.1')]),
			'padded versions' => self::tar(['appinfo/info.xml' => self::info(' 30 ', "\t32\n")]),
			'bad versions' => self::tar(['appinfo/info.xml' => self::info('30a', '32.')]),
			'prefixed versions' => self::tar(['appinfo/info.xml' => self::info('v30', 'x32')]),
			'no range' => self::tar(['appinfo/info.xml' => self::info(null, null)]),
			'zero block first' => str_repeat("\0", 512) . self::tar(['appinfo/info.xml' => $info]),
			'truncated' => substr(self::tar(['appinfo/info.xml' => $info]), 0, 300),
			'no end marker' => self::tar(['x.txt' => 'x'], true, false),
			'garbage size' => self::tar(['x.txt#zz' => 'x', 'appinfo/info.xml' => $info]),
			'size too large' => self::tar(['x.txt#77777777777' => 'x', 'appinfo/info.xml' => $info]),
			'bad gzip' => "\x1f\x8bnot really gzip",
			'empty archive' => '',
		];
	}

	/**
	 * @return array<string, array{0: ?string, 1: bool}>
	 */
	private static function clears(): array {
		return [
			'all' => [null, false],
			'one app' => ['someapp', false],
			'missing app' => ['nothere', false],
			'invalid app id' => ['Some App', false],
			'all, broken' => [null, true],
			'one app, broken' => ['someapp', true],
			'invalid app id, broken' => ['Some App', true],
		];
	}

	/**
	 * Each case: meta files by version (null: no meta file, a string: raw
	 * meta content; a `file:` key adds that file as is), and the retention.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	private static function prunes(): array {
		$at = static fn (string $time): string => (string)json_encode(['cachedAt' => $time]);
		$three = ['1.0.0' => $at('2026-01-03T00:00:00+00:00'), '2.0.0' => $at('2026-01-01T00:00:00+00:00'), '3.0.0' => $at('2026-01-02T00:00:00+00:00')];

		return [
			'keep three of three' => [$three, 3],
			'keep two of three' => [$three, 2],
			'keep one of three' => [$three, 1],
			'keep zero' => [$three, 0],
			'keep more' => [$three, 9],
			'broken and missing dates' => [['1.0.0' => '{', '2.0.0' => '[]', '3.0.0' => $at('2026-01-01T00:00:00+00:00'), '4.0.0' => '{"cachedAt": 5}', '5.0.0' => '"x"', '6.0.0' => $at('2025-12-31T00:00:00+00:00')], 2],
			'archive without meta' => [['1.0.0' => null, '2.0.0' => $at('2026-01-01T00:00:00+00:00')], 0],
			'stray json file' => [['file:2.0.0.meta.jsox' => '{"cachedAt": "0"}', '2.0.0' => $at('2026-01-01T00:00:00+00:00')], 1],
			'deep meta' => [['1.0.0' => str_repeat('[', 20) . str_repeat(']', 20), '2.0.0' => $at('2026-01-01T00:00:00+00:00')], 0],
		];
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->calls[] = ['log.warning', $message, $context];
		});

		return $logger;
	}

	private function cache(IAppData $appData, int $keep = 3): ArtifactCache {
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($appData);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturn($keep);

		return new ArtifactCache($factory, $config, $this->createMock(ITimeFactory::class), $this->logger());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function runSpec(?string $archive, int $keep = 3, ?string $sha = null, string $appId = 'someapp'): array {
		$this->calls = [];
		$appData = new FakeAppData();
		if ($archive !== null) {
			$folder = $appData->newFolder('artifact-cache-someapp');
			$folder->newFile('1.0.0.tar.gz', $archive);
			$folder->newFile('1.0.0.meta.json', (string)json_encode(['sha256' => $sha ?? hash('sha256', $archive)]));
		}
		$result = $this->cache($appData, $keep)->platformSpecFor($appId, '1.0.0');

		return ['result' => $result, 'calls' => $this->calls, 'left' => self::listing($appData)];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function runClear(?string $appId, bool $broken): array {
		$this->calls = [];
		$appData = new FakeAppData();
		foreach (['artifact-cache-someapp', 'artifact-cache-other', 'unrelated'] as $name) {
			$appData->newFolder($name)->newFile('1.0.0.meta.json', '{}');
		}
		if ($broken) {
			$appData = $this->brokenAppData($appData);
		}
		$this->cache($appData)->clear($appId);

		return ['calls' => $this->calls, 'left' => self::listing($appData)];
	}

	private function brokenAppData(FakeAppData $inner): IAppData {
		$listing = [];
		foreach ($inner->getDirectoryListing() as $folder) {
			$broken = $this->createMock(ISimpleFolder::class);
			$broken->method('getName')->willReturn($folder->getName());
			$broken->method('delete')->willThrowException(new RuntimeException('cannot delete ' . $folder->getName()));
			$listing[] = $broken;
		}
		$appData = $this->createMock(IAppData::class);
		$appData->method('getDirectoryListing')->willReturn($listing);
		$appData->method('getFolder')->willThrowException(new RuntimeException('storage offline'));

		return $appData;
	}

	/**
	 * @param array<string, mixed> $metas
	 * @return array<string, mixed>
	 */
	private function runPrune(array $metas, int $keep): array {
		$this->calls = [];
		$appData = new FakeAppData();
		$folder = $appData->newFolder('artifact-cache-someapp');
		foreach ($metas as $version => $meta) {
			if (str_starts_with($version, 'file:')) {
				$folder->newFile(substr($version, 5), $meta);
				continue;
			}
			$folder->newFile($version . '.tar.gz', 'archive ' . $version);
			if (is_string($meta)) {
				$folder->newFile($version . '.meta.json', $meta);
			}
		}
		$cache = $this->cache($appData);
		(new ReflectionMethod($cache, 'prune'))->invoke($cache, $folder, $keep);

		return ['calls' => $this->calls, 'left' => self::listing($appData)];
	}

	/**
	 * @return array<string, list<string>>|string
	 */
	private static function listing(IAppData $appData): array|string {
		if (!$appData instanceof FakeAppData) {
			return 'mock';
		}
		$left = [];
		foreach ($appData->getDirectoryListing() as $folder) {
			$left[$folder->getName()] = array_map(static fn ($file): string => $file->getName(), $folder->getDirectoryListing());
		}

		return $left;
	}
}
