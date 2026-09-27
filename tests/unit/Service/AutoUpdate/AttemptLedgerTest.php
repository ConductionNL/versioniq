<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AttemptLedgerTest extends TestCase {
	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	public function testHasAttemptedIsFalseWhenLedgerIsEmpty(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$ledger = new AttemptLedger($config, $this->logger());

		$this->assertFalse($ledger->hasAttempted('openregister', '2.3.4'));
	}

	public function testHasAttemptedIsTrueForARecordedVersion(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'2.3.4' => ['at' => '2026-07-22T02:00:00+00:00', 'outcome' => 'failure'],
		], JSON_THROW_ON_ERROR));

		$ledger = new AttemptLedger($config, $this->logger());

		$this->assertTrue($ledger->hasAttempted('openregister', '2.3.4'));
		$this->assertFalse($ledger->hasAttempted('openregister', '2.4.0'));
	}

	public function testHasAttemptedIsFalseOnMalformedJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not valid json');

		$ledger = new AttemptLedger($config, $this->logger());

		$this->assertFalse($ledger->hasAttempted('openregister', '2.3.4'));
	}

	public function testRecordWritesTheEntry(): void {
		$captured = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'auto_attempt.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$ledger = new AttemptLedger($config, $this->logger());
		$ledger->record('openregister', '2.3.4', AttemptLedger::OUTCOME_SUCCESS, '2026-07-23T02:00:00+00:00');

		$decoded = json_decode((string)$captured, true);
		$this->assertSame('success', $decoded['2.3.4']['outcome']);
		$this->assertSame('2026-07-23T02:00:00+00:00', $decoded['2.3.4']['at']);
	}

	public function testRecordPrunesToTheMostRecentTenEntries(): void {
		$existing = [];
		for ($i = 1; $i <= 10; $i++) {
			$existing["1.0.{$i}"] = ['at' => sprintf('2026-07-%02dT00:00:00+00:00', $i), 'outcome' => 'failure'];
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode($existing, JSON_THROW_ON_ERROR));

		$captured = null;
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'auto_attempt.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$ledger = new AttemptLedger($config, $this->logger());
		$ledger->record('openregister', '1.0.11', AttemptLedger::OUTCOME_SUCCESS, '2026-07-23T00:00:00+00:00');

		$decoded = json_decode((string)$captured, true);
		$this->assertCount(10, $decoded);
		$this->assertArrayNotHasKey('1.0.1', $decoded);
		$this->assertArrayHasKey('1.0.11', $decoded);
		$this->assertArrayHasKey('1.0.2', $decoded);
	}

	/**
	 * A failed automatic update blocked that version for good: nothing could
	 * clear the ledger short of occ config:app:delete (#438).
	 */
	public function testForgetClearsOneVersionSoItIsTriedAgain(): void {
		$stored = json_encode([
			'2.3.4' => ['at' => '2026-07-22T02:00:00+00:00', 'outcome' => 'failure'],
			'2.3.3' => ['at' => '2026-07-20T02:00:00+00:00', 'outcome' => 'success'],
		], JSON_THROW_ON_ERROR);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static function () use (&$stored): string {
			return $stored;
		});
		$config->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use (&$stored): bool {
			$stored = $value;
			return true;
		});

		$ledger = new AttemptLedger($config, $this->logger());

		$this->assertTrue($ledger->forget('openregister', '2.3.4'));
		$this->assertFalse($ledger->hasAttempted('openregister', '2.3.4'));
		$this->assertTrue($ledger->hasAttempted('openregister', '2.3.3'));
		$this->assertFalse($ledger->forget('openregister', '9.9.9'));
	}

	public function testBlockedVersionsListsOnlyFailures(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'2.3.4' => ['at' => '2026-07-22T02:00:00+00:00', 'outcome' => 'failure'],
			'2.3.3' => ['at' => '2026-07-20T02:00:00+00:00', 'outcome' => 'success'],
		], JSON_THROW_ON_ERROR));

		$ledger = new AttemptLedger($config, $this->logger());

		$this->assertSame([['version' => '2.3.4', 'at' => '2026-07-22T02:00:00+00:00']], $ledger->blockedVersions('openregister'));
	}
}
