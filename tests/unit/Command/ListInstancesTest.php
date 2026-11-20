<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\ListInstances;
use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\ManifestBuilder;
use OCA\Versioniq\Service\Instance\RemoteInstanceReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:instances`: one row per app with a column per instance, the
 * differing rows marked, cached reads unless --refresh, and the JSON with each
 * read time (install-multi-instance-promotion task 5.1).
 *
 * @covers \OCA\Versioniq\Command\ListInstances
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnection
 */
final class ListInstancesTest extends TestCase {
	private int $fetches = 0;

	private function tester(): CommandTester {
		$connection = new InstanceConnection([
			'id' => 'acceptance', 'name' => 'Acceptance', 'stage' => 'acceptance', 'order' => 1,
			'baseUrl' => 'https://acc.example.org', 'username' => 'reader', 'encryptedPassword' => 'x',
			'addedBy' => 'alice', 'addedAt' => '2026-11-01T00:00:00+00:00',
		]);
		$store = $this->createMock(InstanceConnectionStore::class);
		$store->method('all')->willReturn([$connection]);

		$read = static fn (string $at): array => ['manifest' => ['apps' => [
			['appId' => 'openregister', 'installedVersion' => '2.4.1'],
			['appId' => 'opencatalogi', 'installedVersion' => '1.9.0'],
		]], 'fetchedAt' => $at, 'error' => null, 'checkedAt' => $at];
		$reader = $this->createMock(RemoteInstanceReader::class);
		$reader->method('cached')->willReturn($read('2026-11-14T08:00:00+00:00'));
		$reader->method('fetch')->willReturnCallback(function () use ($read): array {
			$this->fetches++;
			return $read('2026-11-15T08:00:00+00:00');
		});

		$manifest = $this->createMock(ManifestBuilder::class);
		$manifest->method('build')->willReturn([
			'instanceName' => 'Production', 'stage' => 'production', 'serverVersion' => '32.0.0', 'versioniqVersion' => '1.6.7',
			'apps' => [
				['appId' => 'openregister', 'installedVersion' => '2.3.0', 'state' => 'enabled', 'sourceId' => null, 'sha256' => null, 'runningSince' => null],
				['appId' => 'opencatalogi', 'installedVersion' => '1.9.0', 'state' => 'enabled', 'sourceId' => null, 'sha256' => null, 'runningSince' => null],
			],
		]);

		return new CommandTester(new ListInstances($store, $reader, $manifest));
	}

	public function testTheTableMarksTheRowWhereTheInstancesDiffer(): void {
		$tester = $this->tester();

		self::assertSame(0, $tester->execute([]));
		self::assertSame(0, $this->fetches);
		$lines = explode("\n", $tester->getDisplay());
		$row = static fn (string $appId): string => (string)current(array_filter($lines, static fn (string $line): bool => str_contains($line, $appId)));
		self::assertStringContainsString('Production (production)', $tester->getDisplay());
		self::assertStringContainsString('Acceptance (acceptance)', $tester->getDisplay());
		self::assertMatchesRegularExpression('/openregister\s*\|\s*2\.3\.0\s*\|\s*2\.4\.1\s*\|\s*Differs/', $row('openregister'));
		self::assertStringNotContainsString('Differs', $row('opencatalogi'));
	}

	public function testJsonCarriesEachReadTimeAndRefreshReadsFirst(): void {
		$tester = $this->tester();

		$tester->execute(['--json' => true, '--refresh' => true]);

		self::assertSame(1, $this->fetches);
		$data = json_decode($tester->getDisplay(), true);
		self::assertSame(['self', 'acceptance'], array_column($data['instances'], 'id'));
		self::assertSame('2026-11-15T08:00:00+00:00', $data['instances'][1]['fetchedAt']);
		self::assertSame(['openregister' => '2.4.1', 'opencatalogi' => '1.9.0'], $data['instances'][1]['apps']);
		self::assertSame('2.3.0', $data['instances'][0]['apps']['openregister']);
	}
}
