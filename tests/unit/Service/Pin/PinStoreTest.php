<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pin;

require_once __DIR__ . '/../../Support/AuditArgs.php';

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinDrift;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PinStoreTest extends TestCase {
	private function auditLogger(): AuditLogger {
		return $this->createMock(AuditLogger::class);
	}

	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	public function testGetReturnsNullWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new PinStore($config, $this->auditLogger(), $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsPinForValidJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'version' => '2.3.0',
			'pinnedBy' => 'alice',
			'pinnedAt' => '2026-06-11T12:00:00+00:00',
		], JSON_THROW_ON_ERROR));

		$store = new PinStore($config, $this->auditLogger(), $this->logger());
		$pin = $store->get('openregister');

		$this->assertNotNull($pin);
		$this->assertSame('2.3.0', $pin->version);
	}

	public function testGetReturnsNullOnMalformedJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not valid json');

		$store = new PinStore($config, $this->auditLogger(), $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullOnInvalidPinPayload(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode(['version' => '2.3.0'], JSON_THROW_ON_ERROR));

		$store = new PinStore($config, $this->auditLogger(), $this->logger());

		$this->assertNull($store->get('openregister'));
	}

	public function testAllReturnsPinsKeyedByAppIdSkippingMalformedEntries(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getAllValues')->willReturn([
			'pin.openregister' => json_encode(['version' => '2.3.0', 'pinnedBy' => 'alice', 'pinnedAt' => '2026-06-11T12:00:00+00:00'], JSON_THROW_ON_ERROR),
			'pin.calendar' => 'not valid json',
		]);

		$store = new PinStore($config, $this->auditLogger(), $this->logger());
		$all = $store->all();

		$this->assertCount(1, $all);
		$this->assertArrayHasKey('openregister', $all);
		$this->assertSame('2.3.0', $all['openregister']->version);
	}

	public function testSetWritesJsonWithoutDriftMarkers(): void {
		$captured = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'pin.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = new PinStore($config, $this->auditLogger(), $this->logger());
		$pin = (new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00'))->withDrift('2.5.0', '2026-06-12T00:00:00+00:00');
		$store->set('openregister', $pin);

		$decoded = json_decode((string)$captured, true);
		$this->assertSame('2.3.0', $decoded['version']);
		$this->assertArrayNotHasKey('driftedTo', $decoded);
	}

	public function testSetRecordsAPinAuditEntry(): void {
		$config = $this->createMock(IAppConfig::class);

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected(
				'alice',
				'openregister',
				AuditLogger::OPERATION_PIN,
				null,
				'2.3.0',
				null,
				AuditLogger::STATUS_SUCCESS,
				'because',
			));

		$store = new PinStore($config, $auditLogger, $this->logger());
		$store->set('openregister', new Pin('2.3.0', 'alice', '2026-06-11T12:00:00+00:00', 'because'));
	}

	public function testClearIsANoOpWhenNoPinExists(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->never())->method('deleteKey');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->never())->method('record');

		$store = new PinStore($config, $auditLogger, $this->logger());
		$store->clear('openregister', 'alice');
	}

	public function testClearDeletesValueAndRecordsUnpinAuditEntry(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'version' => '2.3.0',
			'pinnedBy' => 'alice',
			'pinnedAt' => '2026-06-11T12:00:00+00:00',
		], JSON_THROW_ON_ERROR));
		$config->expects($this->once())
			->method('deleteKey')
			->with(Application::APP_ID, 'pin.openregister');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected('alice', 'openregister', AuditLogger::OPERATION_UNPIN, '2.3.0', null, null, AuditLogger::STATUS_SUCCESS, null));

		$store = new PinStore($config, $auditLogger, $this->logger());
		$store->clear('openregister', 'alice');
	}

	public function testMarkDriftReturnsFalseWhenNoPinExists(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->never())->method('record');

		$store = new PinStore($config, $auditLogger, $this->logger());

		$this->assertFalse($store->markDrift('openregister', new PinDrift('2.5.0', '2026-06-12T00:00:00+00:00')));
	}

	public function testMarkDriftRecordsANewDriftAndAuditsAsSystem(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'version' => '2.3.0',
			'pinnedBy' => 'alice',
			'pinnedAt' => '2026-06-11T12:00:00+00:00',
		], JSON_THROW_ON_ERROR));
		$config->expects($this->once())->method('setValueString');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(...AuditArgs::expected('system', 'openregister', AuditLogger::OPERATION_PIN_DRIFT, '2.3.0', '2.5.0', null, AuditLogger::STATUS_SUCCESS, null));

		$store = new PinStore($config, $auditLogger, $this->logger());

		$this->assertTrue($store->markDrift('openregister', new PinDrift('2.5.0', '2026-06-12T00:00:00+00:00')));
	}

	public function testMarkDriftIsIdempotentForTheSameDriftedVersion(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'version' => '2.3.0',
			'pinnedBy' => 'alice',
			'pinnedAt' => '2026-06-11T12:00:00+00:00',
			'driftedTo' => '2.5.0',
			'driftedAt' => '2026-06-12T00:00:00+00:00',
		], JSON_THROW_ON_ERROR));
		$config->expects($this->never())->method('setValueString');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->never())->method('record');

		$store = new PinStore($config, $auditLogger, $this->logger());

		$this->assertFalse($store->markDrift('openregister', new PinDrift('2.5.0', '2026-06-13T00:00:00+00:00')));
	}
}
