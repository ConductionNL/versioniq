<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

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
		self::assertSame(['updates' => [], 'checkedAt' => null, 'status' => null, 'errors' => []], $this->store()->read());
	}

	public function testASavedSnapshotReadsBackWithItsTime(): void {
		$store = $this->store();
		$store->save(['deck' => ['installedVersion' => '1.0.0', 'newestCompatibleVersion' => '1.1.0']], 1_790_000_000);

		self::assertSame(
			['updates' => ['deck' => ['installedVersion' => '1.0.0', 'newestCompatibleVersion' => '1.1.0']], 'checkedAt' => 1_790_000_000, 'status' => 'ok', 'errors' => []],
			$store->read(),
		);
	}

	public function testAnUnencodableSnapshotKeepsThePreviousOne(): void {
		$store = $this->store();
		$store->save(['deck' => ['installedVersion' => '1.0.0']], 1_790_000_000);
		$store->save(['deck' => ['installedVersion' => "\xB1\x31"]], 1_790_000_600);

		self::assertSame(['updates' => ['deck' => ['installedVersion' => '1.0.0']], 'checkedAt' => 1_790_000_000, 'status' => 'ok', 'errors' => []], $store->read());
	}

	/**
	 * A sweep that could not reach some sources is partial and names them; one
	 * that reached none is failed.
	 *
	 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function testAPartialAndAFailedSweepSaySo(): void {
		$store = $this->store();
		$store->save(['deck' => ['error' => null], 'pipelinq' => ['error' => 'source did not answer']], 1_790_000_000);
		$read = $store->read();
		self::assertSame('partial', $read['status']);
		self::assertSame(['pipelinq'], $read['errors']);

		$store->save(['pipelinq' => ['error' => 'source did not answer']], 1_790_000_100);
		self::assertSame('failed', $store->read()['status']);
	}

	/**
	 * A sweep that failed as a whole keeps the previous snapshot and says
	 * failed at the time of the attempt; with no snapshot at all it is still
	 * reported, not "never checked".
	 *
	 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public function testAWholeFailureKeepsTheSnapshotAndSaysFailed(): void {
		$store = $this->store();
		$store->saveFailure(1_790_000_000);
		self::assertSame(['updates' => [], 'checkedAt' => 1_790_000_000, 'status' => 'failed', 'errors' => []], $store->read());

		$store->save(['deck' => ['installedVersion' => '1.0.0']], 1_790_000_100);
		$store->saveFailure(1_790_000_200);
		self::assertSame(['updates' => ['deck' => ['installedVersion' => '1.0.0']], 'checkedAt' => 1_790_000_200, 'status' => 'failed', 'errors' => []], $store->read());
	}

	/**
	 * A snapshot stored before outcomes were recorded gets the outcome its entries give.
	 */
	public function testALegacySnapshotDerivesItsOutcome(): void {
		$this->values['availability.results'] = '{"deck":{"error":"timeout"},"notes":{"error":null}}';
		$this->values['availability.results.checkedAt'] = 1_790_000_000;

		$read = $this->store()->read();
		self::assertSame('partial', $read['status']);
		self::assertSame(['deck'], $read['errors']);
	}
}
