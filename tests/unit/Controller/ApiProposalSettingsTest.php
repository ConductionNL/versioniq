<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The notice settings and the approval flag on a policy
 * (autoupdate-proposed-updates, tasks 2.1 and 3.1).
 */
final class ApiProposalSettingsTest extends TestCase {
	use BuildsByParameterName;

	private AutoUpdateSettingsStore&MockObject $settings;
	private PolicyStore&MockObject $policies;
	private IGroupManager&MockObject $groupManager;

	protected function setUp(): void {
		$this->settings = $this->createMock(AutoUpdateSettingsStore::class);
		$this->policies = $this->createMock(PolicyStore::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(true);
	}

	public function testANoticePeriodAbove60DaysIsRefusedAndNothingStored(): void {
		$this->settings->expects($this->never())->method('setNoticeDays');
		$this->settings->expects($this->never())->method('setEnabled');

		$response = $this->controller()->updateAutoUpdateSettings('1', '61');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('noticeDays must be a whole number from 0 to 60.', $response->getData()['message']);
	}

	public function testANoticePeriodThatIsNotANumberIsRefused(): void {
		$this->assertSame(400, $this->controller()->updateAutoUpdateSettings(null, '-1')->getStatus());
		$this->assertSame(400, $this->controller()->updateAutoUpdateSettings(null, 'two weeks')->getStatus());
	}

	public function testAnUnknownNoticeGroupIsRefused(): void {
		$this->groupManager->method('groupExists')->with('nobody')->willReturn(false);
		$this->settings->expects($this->never())->method('setNoticeGroup');

		$response = $this->controller()->updateAutoUpdateSettings(null, '14', 'nobody');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('noticeGroup is not an existing group.', $response->getData()['message']);
	}

	public function testANoticePeriodAndGroupAreStoredAndReturned(): void {
		$this->groupManager->method('groupExists')->with('functioneel-beheer')->willReturn(true);
		$this->settings->expects($this->once())->method('setNoticeDays')->with(14);
		$this->settings->expects($this->once())->method('setNoticeGroup')->with('functioneel-beheer');
		$this->settings->method('getNoticeDays')->willReturn(14);
		$this->settings->method('getNoticeGroup')->willReturn('functioneel-beheer');

		$response = $this->controller()->updateAutoUpdateSettings(null, '14', ' functioneel-beheer ');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(14, $response->getData()['noticeDays']);
		$this->assertSame('functioneel-beheer', $response->getData()['noticeGroup']);
	}

	public function testAnEmptyGroupClearsItAndOmittedFieldsAreLeftAlone(): void {
		$this->settings->expects($this->never())->method('setNoticeDays');
		$this->settings->expects($this->once())->method('setNoticeGroup')->with('');

		$this->assertSame(200, $this->controller()->updateAutoUpdateSettings(null, null, '')->getStatus());
	}

	public function testSetPolicyStoresRequireApproval(): void {
		$this->policies->expects($this->once())->method('set')
			->with('openregister', $this->callback(static fn (Policy $p): bool => $p->level === 'patch' && $p->requireApproval));

		$response = $this->controller(['level' => 'patch'])->setPolicy('openregister', '1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['policy']['requireApproval']);
	}

	public function testSetPolicyWithoutTheFlagKeepsTheStoredApproval(): void {
		$this->policies->method('get')->willReturn(new Policy('patch', 'admin', '2026-07-01T00:00:00+00:00', true));
		$this->policies->expects($this->once())->method('set')
			->with('openregister', $this->callback(static fn (Policy $p): bool => $p->level === 'minor' && $p->requireApproval));

		$this->controller(['level' => 'minor'])->setPolicy('openregister');
	}

	public function testSetPolicyWithAFalseFlagTurnsApprovalOff(): void {
		$this->policies->method('get')->willReturn(new Policy('patch', 'admin', '2026-07-01T00:00:00+00:00', true));
		$this->policies->expects($this->once())->method('set')
			->with('openregister', $this->callback(static fn (Policy $p): bool => !$p->requireApproval));

		$this->controller(['level' => 'patch'])->setPolicy('openregister', '');
	}

	/**
	 * @param array<string, string> $params
	 */
	private function controller(array $params = []): ApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-24T00:00:00+00:00'));

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $this->groupManager,
			'timeFactory' => $time,
			'policyStore' => $this->policies,
			'settingsStore' => $this->settings,
			'connectionReports' => null,
			'serverVersion' => (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
		]);
	}
}
