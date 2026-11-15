<?php

/**
 * Tests for the repair step that copies app config from the old app id.
 *
 * @category  Test
 * @package   OCA\Versioniq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Repair;

use OCA\Versioniq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Versioniq\Repair\MigrateAppConfigKeys
 * @uses \OCA\Versioniq\Repair\ConfigKeyMigrationRun
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 *
 * @spec exclude One-off app_versions->versioniq rename plumbing.
 */
final class MigrateAppConfigKeysTest extends TestCase {

	/** @var list<list<mixed>> */
	private array $calls = [];

	/**
	 * Every kind of key, and the summary line that counts them.
	 */
	public function testEveryKindOfKeyIsCountedAndOnlyTheMissingOnesAreCopied(): void {
		$stored = [
			'app_versions' => ['enabled' => 'yes', 'trusted_sources' => '["a"]', 'pin.notes' => '{}', 'cache.keep' => '', 'broken' => 'x', 'types' => 'x'],
			'versioniq' => ['pin.notes' => '{"kept":1}'],
		];
		$this->runStep(keys: ['enabled', 'trusted_sources', 'pin.notes', 'cache.keep', 'broken', 'types'], stored: $stored, throwOn: 'broken');

		$this->assertSame([
			['getKeys', 'app_versions'],
			['get', 'app_versions', 'trusted_sources'],
			['get', 'versioniq', 'trusted_sources'],
			['set', 'versioniq', 'trusted_sources', '["a"]'],
			['get', 'app_versions', 'pin.notes'],
			['get', 'versioniq', 'pin.notes'],
			['get', 'app_versions', 'cache.keep'],
			['get', 'app_versions', 'broken'],
			['warning', 'Versioniq: could not migrate one app config key; leaving it under the old namespace', ['key' => 'broken', 'exception' => 'read failed']],
			['info', 'MigrateAppConfigKeys: 1 key(s) migrated, 1 already present, 1 had no value to migrate, 2 skipped as Nextcloud-reserved.'],
		], $this->calls);
	}

	public function testNothingStoredIsNothingToDo(): void {
		$this->runStep(keys: [], stored: [], throwOn: null);

		$this->assertSame([
			['getKeys', 'app_versions'],
			['info', 'MigrateAppConfigKeys: no stored app_versions configuration on this install; nothing to do.'],
		], $this->calls);
	}

	public function testUnreadableKeysAreSkippedWithAWarning(): void {
		$this->runStep(keys: null, stored: [], throwOn: null);

		$this->assertSame([
			['getKeys', 'app_versions'],
			['warning', 'Versioniq: could not enumerate app_versions app config keys; skipping the migration', ['exception' => 'no keys']],
			['info', 'MigrateAppConfigKeys: no stored app_versions configuration on this install; nothing to do.'],
		], $this->calls);
	}

	/**
	 * @param list<string>|null $keys The stored old keys, or null when listing them throws.
	 * @param array<string, array<string, string>> $stored Values by app id and key.
	 */
	private function runStep(?array $keys, array $stored, ?string $throwOn): void {
		$this->calls = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->willReturnCallback(function (string $app) use ($keys): array {
			$this->calls[] = ['getKeys', $app];
			if ($keys === null) {
				throw new RuntimeException('no keys');
			}

			return $keys;
		});
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($stored, $throwOn): string {
			$this->calls[] = ['get', $app, $key];
			if ($key === $throwOn) {
				throw new RuntimeException('read failed');
			}

			return $stored[$app][$key] ?? $default;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->calls[] = ['set', $app, $key, $value];

			return true;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
			$this->calls[] = ['warning', $message, $context];
		});
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['info', $message];
		});

		(new MigrateAppConfigKeys($config, $logger))->run($output);
	}
}
