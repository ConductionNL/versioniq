<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the admin recipient list every notifier builds: for each
 * notifier and each admin-group shape, the uids are compared, as JSON text,
 * with fixtures/admin-uids.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier
 * @covers \OCA\Versioniq\Service\Advisory\AdvisoryNotifier
 * @covers \OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier
 * @covers \OCA\Versioniq\Service\Pin\PinDriftHandler
 * @covers \OCA\Versioniq\Service\ResolvesAdminUids
 */
final class AdminUidsGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/admin-uids.golden.json';

	private const CLASSES = [
		PinDriftHandler::class,
		AutoUpdateNotifier::class,
		AdvisoryNotifier::class,
		AdvisoryDigestNotifier::class,
	];

	/**
	 * @return array<string, list<string>|null> group shape name => member uids (null: no admin group)
	 */
	private function shapes(): array {
		return [
			'no admin group' => null,
			'empty group' => [],
			'one admin' => ['alice'],
			'duplicates and order' => ['carol', 'alice', 'carol', 'bob', 'alice', '0', ''],
		];
	}

	private function groupManager(?array $uids): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$asked = [];
		$group = null;
		if ($uids !== null) {
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(array_map(function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			}, $uids));
		}
		$manager->method('get')->willReturnCallback(function (string $gid) use ($group, &$asked) {
			$asked[] = $gid;

			return $gid === 'admin' ? $group : null;
		});

		return $manager;
	}

	public function testAdminUidsMatchTheGoldenFixture(): void {
		$actual = [];
		foreach (self::CLASSES as $class) {
			$reflection = new \ReflectionClass($class);
			foreach ($this->shapes() as $name => $uids) {
				$instance = $this->buildByName($class, ['groupManager' => $this->groupManager($uids)]);
				$method = $reflection->getMethod('adminUids');
				$actual[$class][$name] = $method->invoke($instance);
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}
}
