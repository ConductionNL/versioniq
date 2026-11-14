<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Instance;

use DateTime;
use InvalidArgumentException;
use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * Connections to other instances: https only, the password stored encrypted and never in the clear,
 * a malformed value read as absent (install-multi-instance-promotion task 1.1).
 *
 * @covers \OCA\Versioniq\Service\Instance\InstanceConnectionStore
 * @covers \OCA\Versioniq\Service\Instance\InstanceConnection
 */
final class InstanceConnectionStoreTest extends TestCase {

	/** @var array<string, string> */
	private array $config = [];

	/** @var array<string, bool> keys stored as sensitive */
	private array $sensitive = [];

	private function store(): InstanceConnectionStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->config));
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->config[$key] = $value;
				$this->sensitive[$key] = $sensitive;
				return true;
			},
		);
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc(' . strrev($plain) . ')');
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => strrev(substr($cipher, 4, -1)));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T08:00:00+00:00'));

		return new InstanceConnectionStore($appConfig, $crypto, $time);
	}

	/** @return array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string} */
	private function acceptance(array $overrides = []): array {
		return $overrides + [
			'id' => 'acceptance',
			'name' => 'Acceptance',
			'stage' => 'acceptance',
			'order' => 1,
			'baseUrl' => 'https://acc.example.org/',
			'username' => 'versioniq-reader',
		];
	}

	public function testASavedConnectionHoldsNoPlaintextPassword(): void {
		$store = $this->store();
		$connection = $store->save($this->acceptance(), 'app-password-123', 'alice');

		self::assertSame('https://acc.example.org', $connection->baseUrl);
		self::assertSame('alice', $connection->addedBy);
		self::assertSame('2026-11-14T08:00:00+00:00', $connection->addedAt);
		self::assertStringNotContainsString('app-password-123', $this->config['instance.acceptance']);
		self::assertTrue($this->sensitive['instance.acceptance']);
		self::assertArrayNotHasKey('encryptedPassword', $connection->toPublicArray());
		self::assertStringNotContainsString('app-password-123', (string)json_encode($connection->toPublicArray()));
		self::assertSame('app-password-123', $store->withPassword($connection, static fn (string $plain): string => $plain));
	}

	public function testAnHttpAddressIsRefused(): void {
		$store = $this->store();
		foreach (['http://acc.example.org', 'https://user:pw@acc.example.org', 'https://acc.example.org/?x=1', 'acc.example.org', ''] as $address) {
			try {
				$store->save($this->acceptance(['baseUrl' => $address]), 'pw', 'alice');
				self::fail('expected a refusal for ' . $address);
			} catch (InvalidArgumentException $error) {
				self::assertStringStartsWith('Enter an https address', $error->getMessage());
			}
		}

		self::assertSame([], $this->config);
	}

	public function testAMalformedStoredValueReadsAsAbsent(): void {
		$store = $this->store();
		$store->save($this->acceptance(['id' => 'test', 'order' => 0]), 'pw', 'alice');
		$this->config['instance.broken'] = '{"id": "broken", "name": "Broken"}';
		$this->config['instance.garbage'] = 'not json';
		$this->config['instance.test.manifest'] = '{"apps": []}';
		$this->config['other.key'] = 'x';

		self::assertNull($store->get('broken'));
		self::assertNull($store->get('garbage'));
		self::assertSame(['test'], array_map(static fn (InstanceConnection $c): string => $c->id, $store->all()));
	}

	public function testConnectionsAreListedInChainOrder(): void {
		$store = $this->store();
		$store->save($this->acceptance(['id' => 'production', 'name' => 'Production', 'stage' => 'production', 'order' => 2]), 'pw', 'alice');
		$store->save($this->acceptance(), 'pw', 'alice');
		$store->save($this->acceptance(['id' => 'test', 'name' => 'Test', 'stage' => 'test', 'order' => 0]), 'pw', 'alice');

		self::assertSame(['test', 'acceptance', 'production'], array_map(static fn (InstanceConnection $c): string => $c->id, $store->all()));
	}

	public function testAChangeWithoutAPasswordKeepsTheStoredOneAndTheAdder(): void {
		$store = $this->store();
		$store->save($this->acceptance(), 'first-password', 'alice');

		$changed = $store->save($this->acceptance(['name' => 'Acceptatie']), '', 'bob');

		self::assertSame('Acceptatie', $changed->name);
		self::assertSame('alice', $changed->addedBy);
		self::assertSame('first-password', $store->withPassword($changed, static fn (string $plain): string => $plain));
	}

	public function testANewConnectionNeedsAPasswordAndValidFields(): void {
		$store = $this->store();
		$cases = [
			[$this->acceptance(), '', 'Enter the app password'],
			[$this->acceptance(['id' => 'Bad Id']), 'pw', 'Use an id of lowercase letters'],
			[$this->acceptance(['id' => 'self']), 'pw', 'Use an id of lowercase letters'],
			[$this->acceptance(['name' => ' ']), 'pw', 'Enter a name'],
			[$this->acceptance(['username' => '']), 'pw', 'Enter a username'],
			[$this->acceptance(['order' => 100]), 'pw', 'Enter an order from 0 to 99'],
			[$this->acceptance(['order' => '-1']), 'pw', 'Enter an order from 0 to 99'],
		];
		foreach ($cases as [$fields, $password, $message]) {
			try {
				$store->save($fields, $password, 'alice');
				self::fail('expected: ' . $message);
			} catch (InvalidArgumentException $error) {
				self::assertStringStartsWith($message, $error->getMessage());
			}
		}

		self::assertSame([], $this->config);
	}

	public function testDeleteRemovesTheConnectionAndItsCachedManifest(): void {
		$store = $this->store();
		$store->save($this->acceptance(), 'pw', 'alice');
		$this->config['instance.acceptance.manifest'] = '{}';

		self::assertTrue($store->delete('acceptance'));
		self::assertSame([], $this->config);
		self::assertFalse($store->delete('acceptance'));
	}

	public function testThisInstanceHasItsOwnStage(): void {
		$store = $this->store();
		self::assertSame(['name' => '', 'stage' => '', 'order' => 0], $store->self());

		$store->setSelf(['name' => 'Production', 'stage' => 'production', 'order' => '2']);

		self::assertSame(['name' => 'Production', 'stage' => 'production', 'order' => 2], $store->self());
		self::assertSame([], $store->all(), 'the self entry is not a connection');
	}
}
