<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ReportController;
use OCA\Versioniq\Service\Audit\AuditPeriod;
use OCA\Versioniq\Service\Report\SecurityReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Controller\ReportController
 * @uses   \OCA\Versioniq\Service\Audit\AuditPeriod
 */
final class ReportControllerTest extends TestCase {
	private function controller(array $params, bool $admin, SecurityReportService $reports): ReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('someone')->willReturn($admin);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T12:00:00+00:00'));

		return new ReportController('versioniq', $request, $reports, $groups, $session, $time);
	}

	public function testANonAdminIsRefusedAndNothingIsRead(): void {
		$reports = $this->createMock(SecurityReportService::class);
		$reports->expects($this->never())->method('report');

		$response = $this->controller(['from' => '2026-11-01', 'to' => '2026-11-30'], false, $reports)->security();

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['message' => 'Forbidden'], $response->getData());
	}

	public function testAMissingOrBadPeriodIsRefused(): void {
		$reports = $this->createMock(SecurityReportService::class);
		$reports->expects($this->never())->method('report');

		$missing = $this->controller(['from' => '2026-11-01'], true, $reports)->security();
		$this->assertSame(400, $missing->getStatus());
		$this->assertSame(['message' => 'A report needs a from and a to date.'], $missing->getData());

		$bad = $this->controller(['from' => '2026-11-01', 'to' => '2026-11-31'], true, $reports)->security();
		$this->assertSame(400, $bad->getStatus());
	}

	public function testAnAdminGetsTheReportForThePeriodInTheirTimeZone(): void {
		$reports = $this->createMock(SecurityReportService::class);
		$reports->expects($this->once())->method('report')
			->with(
				$this->callback(fn (AuditPeriod $p): bool => $p->fromUtc === '2026-10-31 23:00:00' && $p->toUtc === '2026-11-30 22:59:59'),
				$this->callback(fn (\DateTimeImmutable $now): bool => $now->format('Y-m-d H:i:s') === '2026-11-14 12:00:00')
			)
			->willReturn(['severities' => []]);

		$response = $this->controller(['from' => '2026-11-01', 'to' => '2026-11-30', 'timeZone' => 'Europe/Amsterdam'], true, $reports)->security();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['severities' => []], $response->getData());
	}
}
