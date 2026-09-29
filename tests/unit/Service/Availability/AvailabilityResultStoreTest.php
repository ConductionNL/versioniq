<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Availability;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/specs/pending-updates/spec.md
 */
final class AvailabilityResultStoreTest extends TestCase {
	/** @var array<string, string|int> */
	private array $values = [];

	private function store(): AvailabilityResultStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->values[$key] ?? $default));
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->values[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			self::assertSame(Application::APP_ID, $app);
			$this->values[$key] = $value;

			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->values[$key] = $value;

			return true;
		});

		return new AvailabilityResultStore($config, new NullLogger());
	}

	public function testNeverSweptReadsAsCheckedAtNull(): void {
		self::assertSame(['updates' => [], 'checkedAt' => null], $this->store()->read());
	}

	public function testASavedSnapshotReadsBackWithItsTime(): void {
		$store = $this->store();
		$store->save(['deck' => ['installedVersion' => '1.0.0', 'newestCompatibleVersion' => '1.1.0']], 1_790_000_000);

		self::assertSame(
			['updates' => ['deck' => ['installedVersion' => '1.0.0', 'newestCompatibleVersion' => '1.1.0']], 'checkedAt' => 1_790_000_000],
			$store->read(),
		);
	}

	public function testAnUnencodableSnapshotKeepsThePreviousOne(): void {
		$store = $this->store();
		$store->save(['deck' => ['installedVersion' => '1.0.0']], 1_790_000_000);
		$store->save(['deck' => ['installedVersion' => "\xB1\x31"]], 1_790_000_600);

		self::assertSame(['updates' => ['deck' => ['installedVersion' => '1.0.0']], 'checkedAt' => 1_790_000_000], $store->read());
	}
}
