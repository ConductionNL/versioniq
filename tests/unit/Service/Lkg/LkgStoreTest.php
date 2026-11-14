<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lkg;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Lkg\Lkg;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class LkgStoreTest extends TestCase {
	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	public function testGetReturnsNullWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new LkgStore($config, $this->logger());

		self::assertNull($store->get('openregister'));
	}

	public function testGetReturnsRecordForValidJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'version' => '2.5.0',
			'recordedAt' => '2026-07-23T12:00:00+00:00',
			'sourceId' => 'appstore',
		], JSON_THROW_ON_ERROR));

		$store = new LkgStore($config, $this->logger());
		$lkg = $store->get('openregister');

		self::assertNotNull($lkg);
		self::assertSame('2.5.0', $lkg->version);
		self::assertSame('appstore', $lkg->sourceId);
	}

	public function testGetReturnsNullOnMalformedJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not valid json');

		$store = new LkgStore($config, $this->logger());

		self::assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullOnInvalidPayload(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode(['version' => '2.5.0'], JSON_THROW_ON_ERROR));

		$store = new LkgStore($config, $this->logger());

		self::assertNull($store->get('openregister'));
	}

	public function testSetWritesTheKeyScopedToTheAppId(): void {
		$captured = null;
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'lkg.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = new LkgStore($config, $this->logger());
		$store->set('openregister', new Lkg('2.5.0', '2026-07-23T12:00:00+00:00', 'appstore'));

		$decoded = json_decode((string)$captured, true);
		self::assertSame('2.5.0', $decoded['version']);
		self::assertSame('appstore', $decoded['sourceId']);
	}

	/**
	 * safety-rollback-backup-and-preflight 1.1: a record stored before
	 * `previous` existed reads with none, and one stored with it reads it back.
	 */
	public function testAnOldRecordReadsWithoutPreviousAndANewOneWithIt(): void {
		$old = $this->createMock(IAppConfig::class);
		$old->method('getValueString')->willReturn(json_encode(['version' => '0.4.46', 'recordedAt' => '2026-10-01T08:00:00+00:00', 'sourceId' => 'appstore'], JSON_THROW_ON_ERROR));
		$lkg = (new LkgStore($old, $this->logger()))->get('procest');
		self::assertNotNull($lkg);
		self::assertNull($lkg->previous);

		$written = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use (&$written): bool {
			$written = $value;

			return true;
		});
		$config->method('getValueString')->willReturnCallback(static function () use (&$written): string {
			return (string)$written;
		});
		$store = new LkgStore($config, $this->logger());
		$store->set('procest', new Lkg('0.4.48', '2026-10-15T08:00:00+00:00', 'appstore', new Lkg('0.4.46', '2026-10-01T08:00:00+00:00', 'github:ConductionNL/procest')));

		$read = $store->get('procest');
		self::assertSame('0.4.48', $read?->version);
		self::assertSame(['version' => '0.4.46', 'recordedAt' => '2026-10-01T08:00:00+00:00', 'sourceId' => 'github:ConductionNL/procest'], $read?->previous?->toArray());
	}
}
