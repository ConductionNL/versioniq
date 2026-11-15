<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for AttemptLedger: a sequence of record/forget calls runs against
 * an in-memory app config, and every config call plus every return value is
 * compared, as JSON text, with fixtures/attempt-ledger.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\AutoUpdate\AttemptLedger
 */
final class AttemptLedgerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/attempt-ledger.golden.json';

	/** @var array<string, string> */
	private array $store = [];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$app . '/' . $key] ?? $default
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->calls[] = ['setValueString', $app, $key, $value];
				$this->store[$app . '/' . $key] = $value;

				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->calls[] = ['deleteKey', $app, $key];
				unset($this->store[$app . '/' . $key]);
			}
		);

		return $config;
	}

	public function testRecordAndForgetMatchTheGoldenFixture(): void {
		$ledger = new AttemptLedger($this->config(), $this->createMock(LoggerInterface::class));
		$steps = [];
		for ($i = 1; $i <= 12; $i++) {
			$outcome = $i % 3 === 0 ? AttemptLedger::OUTCOME_SUCCESS : AttemptLedger::OUTCOME_FAILURE;
			$ledger->record('openregister', "1.0.{$i}", $outcome, sprintf('2026-07-%02dT00:00:00+00:00', 13 - $i));
			$steps[] = ['record', "1.0.{$i}", $outcome];
		}
		$ledger->record('openregister', '1.0.5', AttemptLedger::OUTCOME_SUCCESS, '2026-08-01T00:00:00+00:00');
		$ledger->record('opencatalogi', '2.0.0', AttemptLedger::OUTCOME_FAILURE, '2026-08-02T00:00:00+00:00');
		$steps[] = ['blocked', $ledger->blockedVersions('openregister')];
		$steps[] = ['forget unknown', $ledger->forget('openregister', '9.9.9')];
		$steps[] = ['forget one', $ledger->forget('openregister', '1.0.1')];
		$steps[] = ['forget last', $ledger->forget('opencatalogi', '2.0.0')];
		$steps[] = ['forget again', $ledger->forget('opencatalogi', '2.0.0')];
		$steps[] = ['attempted', $ledger->hasAttempted('openregister', '1.0.5'), $ledger->hasAttempted('opencatalogi', '2.0.0')];

		$json = json_encode(['steps' => $steps, 'calls' => $this->calls, 'store' => $this->store], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}
}
