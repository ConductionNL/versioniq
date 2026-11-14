<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Pin\PinStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every audit row says where it came from, and reaches the platform's audit log.
 *
 * @covers \OCA\Versioniq\Service\Audit\AuditOrigin
 * @covers \OCA\Versioniq\Service\Audit\AuditLogger
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md
 */
final class AuditOriginAndForwardingTest extends TestCase {
	/** @var list<AuditEntry> */
	private array $inserted = [];

	/** @var list<object> */
	private array $dispatched = [];

	protected function tearDown(): void {
		AuditOrigin::useSapi(null);
		parent::tearDown();
	}

	public function testTheOriginFollowsTheProcessOutsideAJobOrListener(): void {
		AuditOrigin::useSapi('cli');
		$this->assertSame('cli', AuditOrigin::current());

		AuditOrigin::useSapi('fpm-fcgi');
		$this->assertSame('web', AuditOrigin::current());
	}

	public function testDuringSetsTheOriginAndRestoresItAfterwardsAlsoOnAThrow(): void {
		AuditOrigin::useSapi('fpm-fcgi');

		$seen = AuditOrigin::during(AuditOrigin::JOB, static fn (): array => [
			AuditOrigin::current(),
			AuditOrigin::during(AuditOrigin::LISTENER, static fn (): string => AuditOrigin::current()),
			AuditOrigin::current(),
		]);
		$this->assertSame(['job', 'listener', 'job'], $seen);
		$this->assertSame('web', AuditOrigin::current());

		try {
			AuditOrigin::during(AuditOrigin::JOB, static function (): void {
				throw new \RuntimeException('install failed');
			});
		} catch (\RuntimeException) {
		}

		$this->assertSame('web', AuditOrigin::current());
	}

	public function testARowCarriesTheOriginOfWhatWroteIt(): void {
		AuditOrigin::useSapi('fpm-fcgi');
		$logger = $this->logger();

		$logger->record($this->subject(), $this->versions(), AuditLogger::STATUS_SUCCESS);
		AuditOrigin::during(AuditOrigin::JOB, fn () => $logger->record($this->subject(), $this->versions(), AuditLogger::STATUS_SUCCESS));
		AuditOrigin::useSapi('cli');
		$logger->record($this->subject(), $this->versions(), AuditLogger::STATUS_SUCCESS);
		$logger->record(new AuditSubject('alice', 'openregister', AuditLogger::OPERATION_INSTALL, AuditOrigin::LISTENER), $this->versions(), AuditLogger::STATUS_SUCCESS);

		$this->assertSame(['web', 'job', 'cli', 'listener'], array_map(static fn (AuditEntry $entry): ?string => $entry->getOrigin(), $this->inserted));
		$this->assertSame('job', $this->inserted[1]->jsonSerialize()['origin']);
	}

	public function testTheDriftListenerWritesAsListener(): void {
		AuditOrigin::useSapi('cli');
		$seen = null;
		$pins = $this->createMock(PinStore::class);
		$pins->method('get')->willReturnCallback(static function () use (&$seen) {
			$seen = AuditOrigin::current();
			return null;
		});
		$handler = new PinDriftHandler(
			$pins,
			new AdminNotificationServices($this->createMock(IManager::class), $this->createMock(IGroupManager::class), $this->createMock(ITimeFactory::class), $this->createMock(IURLGenerator::class)),
			$this->createMock(LoggerInterface::class),
		);

		$handler->handle('openregister', '2.5.0');

		$this->assertSame('listener', $seen);
		$this->assertSame('cli', AuditOrigin::current());
	}

	public function testEachRowIsForwardedAsACriticalActionWithTheRedactedMessage(): void {
		AuditOrigin::useSapi('fpm-fcgi');
		$logger = $this->logger();

		$logger->record($this->subject(), $this->versions(), AuditLogger::STATUS_FAILURE, 'download refused: Authorization: Bearer ghp_secret123');

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(CriticalActionPerformedEvent::class, $event);
		$this->assertSame('Versioniq %s of app "%s" from %s to %s by "%s" (%s): %s %s', $event->getLogMessage());
		$this->assertSame(
			[
				'operation' => 'install',
				'appId' => 'openregister',
				'fromVersion' => '2.3.0',
				'toVersion' => '2.5.0',
				'actorUid' => 'alice',
				'origin' => 'web',
				'status' => 'failure',
				'message' => 'download refused: Authorization: [redacted] [redacted]',
			],
			$event->getParameters()
		);
		$this->assertStringNotContainsString('ghp_secret123', (string)json_encode($event->getParameters()));
	}

	public function testARowThatWasNotWrittenIsNotForwarded(): void {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willThrowException(new \RuntimeException('table missing'));
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		(new AuditLogger($mapper, $this->time(), $this->createMock(LoggerInterface::class), $dispatcher))
			->record($this->subject(), $this->versions(), AuditLogger::STATUS_SUCCESS);
	}

	public function testAFailingListenerNeverReachesTheOperation(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new \RuntimeException('admin_audit broken'));
		$log = $this->createMock(LoggerInterface::class);
		$log->expects($this->once())->method('warning');

		(new AuditLogger($this->createMock(AuditEntryMapper::class), $this->time(), $log, $dispatcher))
			->record($this->subject(), $this->versions(), AuditLogger::STATUS_SUCCESS);

		$this->addToAssertionCount(1);
	}

	private function logger(): AuditLogger {
		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willReturnCallback(function (AuditEntry $entry): AuditEntry {
			$this->inserted[] = $entry;
			return $entry;
		});
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->dispatched[] = $event;
		});

		return new AuditLogger($mapper, $this->time(), $this->createMock(LoggerInterface::class), $dispatcher);
	}

	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T09:00:00+00:00'));
		return $time;
	}

	private function subject(): AuditSubject {
		return new AuditSubject('alice', 'openregister', AuditLogger::OPERATION_INSTALL);
	}

	private function versions(): AuditedVersions {
		return new AuditedVersions('2.3.0', '2.5.0', 'appstore');
	}
}
