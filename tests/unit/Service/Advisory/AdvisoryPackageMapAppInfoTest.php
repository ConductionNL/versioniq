<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryPackageMap;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit test for AdvisoryPackageMap when an app's info cannot be read: the
 * app still resolves by id, its display name is simply unknown, the failure
 * is logged at debug level, and the index is built once per instance.
 *
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryPackageMap
 */
final class AdvisoryPackageMapAppInfoTest extends TestCase {
	public function testAnUnreadableAppInfoIsLoggedAndTheAppResolvesByIdOnly(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn(['spreed', 'deck']);
		$apps->method('getAppInfo')->willReturnCallback(static function (string $appId): array {
			if ($appId === 'spreed') {
				throw new RuntimeException('info.xml unreadable');
			}

			return ['name' => 'Deck'];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('AdvisoryPackageMap: could not read app info', ['app' => 'spreed', 'message' => 'info.xml unreadable']);
		$map = new AdvisoryPackageMap($apps, $logger);

		$this->assertSame('spreed', $map->resolve('spreed'));
		$this->assertNull($map->resolve('Talk'));
		$this->assertSame('deck', $map->resolve('Deck'));
	}

	public function testAnAppInfoThatIsNotAnArrayOnlyIndexesTheId(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn(['notes']);
		$apps->method('getAppInfo')->willReturn(null);
		$map = new AdvisoryPackageMap($apps, $this->createMock(LoggerInterface::class));

		$this->assertSame('notes', $map->resolve('Notes'));
		$this->assertNull($map->resolve('Note taking'));
	}

	public function testANameThatIsNeitherTextNorAMapAddsNothing(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getEnabledApps')->willReturn(['deck']);
		$apps->method('getAppInfo')->willReturn(['name' => 42]);
		$map = new AdvisoryPackageMap($apps, $this->createMock(LoggerInterface::class));

		$this->assertSame('deck', $map->resolve('deck'));
		$this->assertNull($map->resolve('42'));
	}

	public function testTheIndexIsBuiltOnceAcrossLookups(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getEnabledApps')->willReturn(['deck']);
		$apps->expects($this->once())->method('getAppInfo')->willReturn(['name' => ['en' => 'Deck', 'de' => 'Kartenstapel', 'x' => 5]]);
		$map = new AdvisoryPackageMap($apps, $this->createMock(LoggerInterface::class));

		$this->assertSame('deck', $map->resolve('Deck'));
		$this->assertSame('deck', $map->resolve('Kartenstapel'));
		$this->assertNull($map->resolve('unknown'));
	}
}
