<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\InstallProgress;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The live progress record of an install (install-live-progress 1.1).
 *
 * @covers \OCA\Versioniq\Service\Installer\InstallProgress
 *
 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
 */
final class InstallProgressTest extends TestCase {
	private const TOKEN = 'k3Jw9-q2Lx_7Hn4Pz8Rt';

	/** @var array<string, array{string, int}> What the cache holds, with each entry's lifetime. */
	private array $store = [];

	private int $now = 1_763_100_000;

	/** @var ICacheFactory&MockObject */
	private ICacheFactory $factory;

	private function progress(?ICache $cache = null, bool $distributed = true): InstallProgress {
		$cache ??= $this->memoryCache();
		$this->factory = $this->createMock(ICacheFactory::class);
		$this->factory->method('isAvailable')->willReturn($distributed);
		$this->factory->method('createDistributed')->with(InstallProgress::CACHE_PREFIX)->willReturn($cache);
		$this->factory->method('createLocal')->with(InstallProgress::CACHE_PREFIX)->willReturn($cache);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new InstallProgress($this->factory, $time, $this->createMock(LoggerInterface::class));
	}

	private function memoryCache(): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl): bool {
			$this->store[$key] = [$value, $ttl];
			return true;
		});
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->store[$key][0] ?? null);
		return $cache;
	}

	public function testStagesAreRecordedInOrderWithTheirTimes(): void {
		$progress = $this->progress();
		$progress->start(self::TOKEN);
		$progress->stage('release-metadata');
		$this->now += 2;
		$progress->stage('downloaded', ['bytes' => 0, 'total' => 2000]);
		$this->now += 1;
		$progress->stage('downloaded', ['bytes' => 1000, 'total' => 2000]);
		$this->now += 3;
		$progress->stage('archive-extracted');

		$record = $progress->read(self::TOKEN);
		$this->assertSame(
			[
				['name' => 'release-metadata', 'startedAt' => 1_763_100_000, 'endedAt' => 1_763_100_002, 'detail' => []],
				['name' => 'downloaded', 'startedAt' => 1_763_100_002, 'endedAt' => 1_763_100_006, 'detail' => ['bytes' => 1000, 'total' => 2000]],
				['name' => 'archive-extracted', 'startedAt' => 1_763_100_006, 'endedAt' => null, 'detail' => []],
			],
			$record['stages']
		);
		$this->assertNull($record['outcome']);
		$this->assertSame(InstallProgress::TTL, $this->store[self::TOKEN][1]);
		$this->assertSame(600, InstallProgress::TTL);
	}

	public function testFinishEndsTheRunningStageAndRecordsTheOutcome(): void {
		$progress = $this->progress();
		$progress->start(self::TOKEN);
		$progress->stage('migrations');
		$this->now += 5;
		$progress->finish('failed');
		$progress->stage('after-finish');

		$record = $progress->read(self::TOKEN);
		$this->assertSame('failed', $record['outcome']);
		$this->assertSame([['name' => 'migrations', 'startedAt' => 1_763_100_000, 'endedAt' => 1_763_100_005, 'detail' => []]], $record['stages']);
	}

	public function testWithoutAValidTokenNothingIsRecorded(): void {
		$progress = $this->progress();
		$progress->stage('downloaded');
		$progress->start('../../etc');
		$progress->stage('downloaded');
		$progress->finish('success');

		$this->assertSame([], $this->store);
		$this->assertNull($progress->read('../../etc'));
		$this->assertNull($progress->read('unknown-token-0000000'));
		$this->assertFalse(InstallProgress::isToken('short'));
		$this->assertTrue(InstallProgress::isToken(self::TOKEN));
	}

	public function testACacheThatThrowsNeverBreaksTheInstall(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willThrowException(new RuntimeException('redis down'));
		$cache->method('get')->willThrowException(new RuntimeException('redis down'));
		$progress = $this->progress(cache: $cache);

		$progress->start(self::TOKEN);
		$progress->stage('downloaded');
		$progress->finish('success');

		$this->assertNull($progress->read(self::TOKEN));
	}

	public function testWithoutADistributedCacheTheLocalOneIsUsed(): void {
		$progress = $this->progress(distributed: false);
		$this->factory->expects($this->once())->method('createLocal');
		$progress->start(self::TOKEN);
		$progress->stage('downloaded');

		$this->assertSame('downloaded', $progress->read(self::TOKEN)['stages'][0]['name']);
	}

	public function testAGarbledRecordReadsAsUnknown(): void {
		$progress = $this->progress();
		$this->store[self::TOKEN] = ['{"outcome":"success"}', 600];
		$this->assertNull($progress->read(self::TOKEN));
		$this->store[self::TOKEN] = ['not json', 600];
		$this->assertNull($progress->read(self::TOKEN));
	}
}
