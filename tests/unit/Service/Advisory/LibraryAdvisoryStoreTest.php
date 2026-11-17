<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Service\Advisory\LibraryAdvisoryStore
 * @uses \OCA\Versioniq\Service\Settings\StoredSnapshot
 */
final class LibraryAdvisoryStoreTest extends TestCase {
	/** @var array<string, string|int> */
	private array $stored = [];

	private function store(?LoggerInterface $logger = null): LibraryAdvisoryStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->stored[$key] ?? $default));
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->stored[$key] = $value;

			return true;
		});

		return new LibraryAdvisoryStore($config, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testNeverCheckedReadsAsEmptyWithoutATime(): void {
		$this->assertSame(['libraries' => [], 'checkedAt' => null], $this->store()->read());
	}

	public function testASavedCheckReadsBackWithItsTime(): void {
		$entry = ['mail' => ['manifest' => 'vendor/composer/installed.json', 'checked' => 2, 'skipped' => 0, 'error' => null, 'findings' => []]];
		$store = $this->store();

		$store->save($entry, 1700000000);

		$this->assertSame(['libraries' => $entry, 'checkedAt' => 1700000000], $store->read());
		$this->assertArrayHasKey('advisory.libraries', $this->stored);
		$this->assertSame(1700000000, $this->stored['advisory.libraries.checkedAt']);
	}

	public function testAnUnencodableCheckKeepsThePreviousSnapshot(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$store = $this->store($logger);
		$store->save(['mail' => ['manifest' => null, 'checked' => 0, 'skipped' => 0, 'error' => null, 'findings' => []]], 1700000000);

		$store->save(['mail' => ['manifest' => "\xB1\x31", 'checked' => 0, 'skipped' => 0, 'error' => null, 'findings' => []]], 1700009999);

		$this->assertSame(1700000000, $store->read()['checkedAt']);
		$this->assertNull($store->read()['libraries']['mail']['manifest']);
	}

	public function testAStoredSnapshotThatIsNotJsonReadsAsNeverChecked(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$this->stored['advisory.libraries'] = '{broken';

		$this->assertSame(['libraries' => [], 'checkedAt' => null], $this->store($logger)->read());
	}
}
