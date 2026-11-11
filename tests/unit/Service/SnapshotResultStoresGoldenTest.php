<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the advisory and availability result stores call for call: the app
 * config reads and writes in order, the log lines and the read result for
 * every branch (nothing stored, malformed JSON, non-array JSON, zero and
 * positive timestamps, an unencodable snapshot).
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 * @spec openspec/specs/pending-updates/spec.md
 */
final class SnapshotResultStoresGoldenTest extends TestCase {
	/** @var array<string, string|int> */
	private array $values = [];

	/** @var list<string> */
	private array $calls = [];

	/** @var list<array{0: string, 1: string, 2: array<array-key, mixed>}> */
	private array $logs = [];

	/**
	 * @return iterable<string, array{0: string, 1: string, 2: string}>
	 */
	public static function stores(): iterable {
		yield 'advisory' => ['advisory', 'advisory.results', 'advisories'];
		yield 'availability' => ['availability', 'availability.results', 'updates'];
	}

	private function store(string $which): AdvisoryResultStore|AvailabilityResultStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = ''): string {
			$this->calls[] = "getValueString $app $key";

			return (string)($this->values[$key] ?? $default);
		});
		$config->method('getValueInt')->willReturnCallback(function (string $app, string $key, int $default = 0): int {
			$this->calls[] = "getValueInt $app $key $default";

			return (int)($this->values[$key] ?? $default);
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->calls[] = "setValueString $app $key $value";
			$this->values[$key] = $value;

			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->calls[] = "setValueInt $app $key $value";
			$this->values[$key] = $value;

			return true;
		});
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/** @param list<array{0: string, 1: string, 2: array<array-key, mixed>}> $logs */
			public function __construct(
				private array &$logs,
			) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->logs[] = [(string)$level, (string)$message, $context];
			}
		};

		return $which === 'advisory' ? new AdvisoryResultStore($config, $logger) : new AvailabilityResultStore($config, $logger);
	}

	#[DataProvider('stores')]
	public function testNothingStoredReadsAsNeverChecked(string $which, string $key, string $field): void {
		self::assertSame([$field => [], 'checkedAt' => null], $this->store($which)->read());
		self::assertSame(["getValueString versioniq $key"], $this->calls);
		self::assertSame([], $this->logs);
	}

	#[DataProvider('stores')]
	public function testMalformedJsonReadsAsNeverCheckedWithAWarning(string $which, string $key, string $field): void {
		$this->values[$key] = '{not json';
		$this->values["$key.checkedAt"] = 1_790_000_000;

		self::assertSame([$field => [], 'checkedAt' => null], $this->store($which)->read());
		self::assertSame(["getValueString versioniq $key"], $this->calls);
		$expected = $which === 'advisory'
			? 'AdvisoryResultStore: stored snapshot is not valid JSON; reporting as never checked'
			: 'AvailabilityResultStore: the stored snapshot is not valid JSON; reporting it as never checked';
		self::assertSame([['warning', $expected, ['message' => 'Syntax error']]], $this->logs);
	}

	#[DataProvider('stores')]
	public function testNonArrayJsonReadsAsNeverCheckedSilently(string $which, string $key, string $field): void {
		$this->values[$key] = '"text"';
		$this->values["$key.checkedAt"] = 1_790_000_000;

		self::assertSame([$field => [], 'checkedAt' => null], $this->store($which)->read());
		self::assertSame(["getValueString versioniq $key"], $this->calls);
		self::assertSame([], $this->logs);
	}

	#[DataProvider('stores')]
	public function testZeroTimestampReadsAsNull(string $which, string $key, string $field): void {
		$this->values[$key] = '{"deck":{"a":1}}';

		self::assertSame([$field => ['deck' => ['a' => 1]], 'checkedAt' => null], $this->store($which)->read());
		self::assertSame(["getValueString versioniq $key", "getValueInt versioniq $key.checkedAt 0"], $this->calls);
	}

	#[DataProvider('stores')]
	public function testSaveWritesSnapshotThenTimeAndReadsBack(string $which, string $key, string $field): void {
		$store = $this->store($which);
		$store->save(['deck' => ['a' => 1]], 1_790_000_000);

		self::assertSame([
			"setValueString versioniq $key {\"deck\":{\"a\":1}}",
			"setValueInt versioniq $key.checkedAt 1790000000",
		], $this->calls);
		self::assertSame([$field => ['deck' => ['a' => 1]], 'checkedAt' => 1_790_000_000], $store->read());
		self::assertSame([], $this->logs);
	}

	#[DataProvider('stores')]
	public function testUnencodableSnapshotWritesNothingAndLogsAnError(string $which, string $key, string $field): void {
		$this->store($which)->save(['deck' => ['v' => "\xB1\x31"]], 1_790_000_000);

		self::assertSame([], $this->calls);
		$expected = $which === 'advisory'
			? 'AdvisoryResultStore: could not encode correlation snapshot; keeping the previous one'
			: 'AvailabilityResultStore: could not encode the snapshot; keeping the previous one';
		self::assertSame([['error', $expected, ['message' => 'Malformed UTF-8 characters, possibly incorrectly encoded']]], $this->logs);
	}
}
