<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\MinAgeExceptionController;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\MinAgeExceptionStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * releases-minimum-age task 3.1: releasing one held version early.
 *
 * @covers \OCA\Versioniq\Controller\MinAgeExceptionController
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
final class MinAgeExceptionControllerTest extends TestCase {
	private MinAgeExceptionStore&MockObject $exceptions;
	private AuditLogger&MockObject $audit;

	protected function setUp(): void {
		$this->exceptions = $this->createMock(MinAgeExceptionStore::class);
		$this->audit = $this->createMock(AuditLogger::class);
	}

	private function controller(bool $admin = true): MinAgeExceptionController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T12:00:00+00:00'));

		return new MinAgeExceptionController(
			'versioniq',
			$this->createMock(IRequest::class),
			$this->exceptions,
			$this->audit,
			new ApiCaller($session, $groups, $time),
		);
	}

	/**
	 * Records every audit row the controller writes.
	 *
	 * @param list<array{0: AuditSubject, 1: AuditedVersions, 2: string, 3: ?string}> $rows
	 */
	private function recordInto(array &$rows): void {
		$this->audit->method('record')->willReturnCallback(static function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null) use (&$rows): void {
			$rows[] = [$subject, $versions, $status, $message];
		});
	}

	public function testANonAdminIsRefusedAndNothingChanges(): void {
		$this->exceptions->expects($this->never())->method('set');
		$this->exceptions->expects($this->never())->method('remove');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(403, $this->controller(false)->addException('openregister', '2.3.2')->getStatus());
		$this->assertSame(403, $this->controller(false)->removeException('openregister', '2.3.2')->getStatus());
	}

	public function testAddingStoresTheExceptionAndWritesOneAuditRow(): void {
		$rows = [];
		$this->recordInto($rows);
		$this->exceptions->method('get')->willReturn(null);
		$this->exceptions->expects($this->once())->method('set')
			->with('openregister', '2.3.2', 'alice', new \DateTimeImmutable('2026-11-14T12:00:00+00:00'));

		$response = $this->controller()->addException('openregister', '2.3.2');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['appId' => 'openregister', 'exception' => ['version' => '2.3.2', 'by' => 'alice', 'at' => '2026-11-14T12:00:00+00:00']],
			$response->getData()
		);
		$this->assertCount(1, $rows);
		[$subject, $versions, $status, $message] = $rows[0];
		$this->assertSame(['alice', 'openregister', AuditLogger::OPERATION_SETTINGS], [$subject->actorUid, $subject->appId, $subject->operation]);
		$this->assertSame('2.3.2', $versions->toVersion);
		$this->assertSame(AuditLogger::STATUS_SUCCESS, $status);
		$this->assertSame('Minimum-age exception for 2.3.2 added', $message);
	}

	public function testANewExceptionSaysWhichOneItReplaces(): void {
		$rows = [];
		$this->recordInto($rows);
		$this->exceptions->method('get')->willReturn(['version' => '2.3.1', 'by' => 'bob', 'at' => '2026-11-10T00:00:00+00:00']);

		$this->controller()->addException('openregister', '2.3.2');

		$this->assertSame(['Minimum-age exception for 2.3.2 added (replaces 2.3.1)'], array_column($rows, 3));
	}

	public function testAnEmptyVersionIsRefused(): void {
		$this->exceptions->expects($this->never())->method('set');

		$this->assertSame(400, $this->controller()->addException('openregister', ' ')->getStatus());
	}

	public function testRemovingAnExceptionThatIsNotThereIsNotFound(): void {
		$this->exceptions->method('get')->willReturn(['version' => '2.3.1', 'by' => 'bob', 'at' => '2026-11-10T00:00:00+00:00']);
		$this->exceptions->expects($this->never())->method('remove');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(404, $this->controller()->removeException('openregister', '2.3.2')->getStatus());
	}

	public function testRemovingAnExceptionWritesOneAuditRow(): void {
		$rows = [];
		$this->recordInto($rows);
		$this->exceptions->method('get')->willReturn(['version' => '2.3.2', 'by' => 'alice', 'at' => '2026-11-10T00:00:00+00:00']);
		$this->exceptions->expects($this->once())->method('remove')->with('openregister');

		$response = $this->controller()->removeException('openregister', '2.3.2');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['appId' => 'openregister', 'version' => '2.3.2', 'cleared' => true], $response->getData());
		$this->assertCount(1, $rows);
		$this->assertSame('alice', $rows[0][0]->actorUid);
		$this->assertSame('Minimum-age exception for 2.3.2 removed by hand', $rows[0][3]);
	}
}
