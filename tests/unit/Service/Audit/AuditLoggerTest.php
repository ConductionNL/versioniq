<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

require_once __DIR__ . '/../../Support/AuditArgs.php';

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AuditLoggerTest extends TestCase {
	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T12:00:00+00:00'));

		return $time;
	}

	public function testRecordInsertsAnEntryWithAllFields(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$captured = null;
		$mapper->expects($this->once())->method('insert')
			->with($this->callback(function (AuditEntry $entry) use (&$captured): bool {
				$captured = $entry;

				return true;
			}));

		$logger = new AuditLogger($mapper, $this->timeFactory(), $this->createMock(LoggerInterface::class));
		AuditArgs::call($logger,
			'alice',
			'openregister',
			AuditLogger::OPERATION_INSTALL,
			'2.5.0',
			'2.3.0',
			'appstore',
			AuditLogger::STATUS_SUCCESS,
			'all good',
		);

		$this->assertInstanceOf(AuditEntry::class, $captured);
		$this->assertSame('alice', $captured->getActorUid());
		$this->assertSame('openregister', $captured->getAppId());
		$this->assertSame('install', $captured->getOperation());
		$this->assertSame('2.5.0', $captured->getFromVersion());
		$this->assertSame('2.3.0', $captured->getToVersion());
		$this->assertSame('appstore', $captured->getSourceId());
		$this->assertSame('success', $captured->getStatus());
		$this->assertSame('all good', $captured->getMessage());
		$this->assertSame('2026-07-23 12:00:00', $captured->getCreatedAt());
	}

	public function testThrowingMapperDoesNotPropagateOutOfRecord(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willThrowException(new \RuntimeException('table missing'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$auditLogger = new AuditLogger($mapper, $this->timeFactory(), $logger);

		// Must not throw.
		AuditArgs::call($auditLogger, 'alice', 'openregister', AuditLogger::OPERATION_INSTALL, null, '2.3.0', 'appstore', AuditLogger::STATUS_SUCCESS);
		$this->addToAssertionCount(1);
	}

	/**
	 * @dataProvider invalidOperationProvider
	 */
	public function testInvalidOperationIsRejectedWithoutInserting(string $operation): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->expects($this->never())->method('insert');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$auditLogger = new AuditLogger($mapper, $this->timeFactory(), $logger);
		AuditArgs::call($auditLogger, 'alice', 'openregister', $operation, null, '2.3.0', 'appstore', AuditLogger::STATUS_SUCCESS);
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalidOperationProvider(): iterable {
		yield 'uppercase' => ['Install'];
		yield 'spaces' => ['in stall'];
		yield 'too long' => [str_repeat('a', 33)];
		yield 'empty' => [''];
		yield 'digits' => ['install2'];
	}

	public function testMessageIsTruncatedToFourThousandCharacters(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$captured = null;
		$mapper->method('insert')->willReturnCallback(function (AuditEntry $entry) use (&$captured): AuditEntry {
			$captured = $entry;

			return $entry;
		});

		$logger = new AuditLogger($mapper, $this->timeFactory(), $this->createMock(LoggerInterface::class));
		AuditArgs::call($logger,
			'alice',
			'openregister',
			AuditLogger::OPERATION_INSTALL,
			null,
			'2.3.0',
			'appstore',
			AuditLogger::STATUS_FAILURE,
			str_repeat('x', 5000),
		);

		$this->assertInstanceOf(AuditEntry::class, $captured);
		$this->assertSame(4000, strlen((string)$captured->getMessage()));
	}

	public function testBearerTokenIsRedactedFromMessage(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$captured = null;
		$mapper->method('insert')->willReturnCallback(function (AuditEntry $entry) use (&$captured): AuditEntry {
			$captured = $entry;

			return $entry;
		});

		$logger = new AuditLogger($mapper, $this->timeFactory(), $this->createMock(LoggerInterface::class));
		AuditArgs::call($logger,
			'alice',
			'openregister',
			AuditLogger::OPERATION_INSTALL,
			null,
			'2.3.0',
			'github:ConductionNL/openregister',
			AuditLogger::STATUS_FAILURE,
			'Could not download: Authorization: Bearer ghp_supersecrettoken1234567890 rejected',
		);

		$this->assertInstanceOf(AuditEntry::class, $captured);
		$message = (string)$captured->getMessage();
		$this->assertStringNotContainsString('ghp_supersecrettoken1234567890', $message);
		$this->assertStringContainsString('[redacted]', $message);
	}

	public function testEmptyStringsAreNormalisedToNull(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$captured = null;
		$mapper->method('insert')->willReturnCallback(function (AuditEntry $entry) use (&$captured): AuditEntry {
			$captured = $entry;

			return $entry;
		});

		$logger = new AuditLogger($mapper, $this->timeFactory(), $this->createMock(LoggerInterface::class));
		AuditArgs::call($logger, 'alice', 'openregister', AuditLogger::OPERATION_BIND_SOURCE, '', '', 'appstore', AuditLogger::STATUS_SUCCESS, '');

		$this->assertInstanceOf(AuditEntry::class, $captured);
		$this->assertNull($captured->getFromVersion());
		$this->assertNull($captured->getToVersion());
		$this->assertNull($captured->getMessage());
	}

	public function testTheRejectionAndTheWriteFailureAreLoggedWithTheirContext(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(static function (string $message, array $context) use ($level, &$logged): void {
				$logged[] = [$level, $message, $context];
			});
		}
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willThrowException(new \RuntimeException('table missing'));
		$auditLogger = new AuditLogger($mapper, $this->timeFactory(), $logger);

		AuditArgs::call($auditLogger, 'alice', 'openregister', 'Bad Op', null, '2.3.0', 'appstore', AuditLogger::STATUS_SUCCESS);
		AuditArgs::call($auditLogger, 'alice', 'openregister', AuditLogger::OPERATION_PIN, null, '2.3.0', 'appstore', AuditLogger::STATUS_FAILURE);

		$this->assertSame([
			['warning', 'AuditLogger: rejected malformed operation name, entry not recorded', ['appId' => 'openregister', 'operation' => 'Bad Op']],
			['error', 'AuditLogger: failed to record audit entry', ['appId' => 'openregister', 'operation' => 'pin', 'message' => 'table missing']],
		], $logged);
	}
}
