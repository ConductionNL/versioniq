<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSchedule;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\SecurityHoldStore;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;

/**
 * `GET /api/policies` carries what the Automatic updates overview needs to
 * say why a security fix waits (autoupdate-security-first 3.2).
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Service\AutoUpdate\SecurityCandidate
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicy
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-the-security-level-installs-only-advisory-fixes-in-the-window
 */
final class ApiPoliciesOverviewTest extends TestCase {
	use BuildsByParameterName;

	private const NOW = 1_784_772_000;

	/** @return array<string, mixed> */
	private function policies(?int $checkedAt, ?PatchPolicy $patchPolicy, array $holds): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$results = $this->createMock(AdvisoryResultStore::class);
		$results->method('read')->willReturn(['advisories' => [], 'checkedAt' => $checkedAt]);
		$settings = $this->createMock(AdvisorySettingsStore::class);
		$settings->method('getIntervalSeconds')->willReturn(6 * 3600);
		$patchPolicies = $this->createMock(PatchPolicyStore::class);
		$patchPolicies->method('read')->willReturn($patchPolicy);
		$holdStore = $this->createMock(SecurityHoldStore::class);
		$holdStore->method('read')->willReturn($holds);
		$autoUpdateSettings = $this->createMock(AutoUpdateSettingsStore::class);
		$autoUpdateSettings->method('getSchedule')->willReturn(AutoUpdateSchedule::everyDay());

		$controller = $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->createMock(IRequest::class),
			'groupManager' => $groups,
			'userSession' => $session,
			'timeFactory' => $time,
			'advisoryResults' => $results,
			'advisorySettings' => $settings,
			'patchPolicyStore' => $patchPolicies,
			'securityHolds' => $holdStore,
			'autoUpdateSettingsStore' => $autoUpdateSettings,
			'settingsStore' => $autoUpdateSettings,
			'connectionReports' => null,
			// ServerVersion is `readonly` and cannot be mocked.
			'serverVersion' => (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
		]);
		$response = $controller->policies();
		$this->assertSame(200, $response->getStatus());

		return $response->getData();
	}

	public function testHoldsFreshnessAndByHandKindsAreListed(): void {
		$data = $this->policies(
			self::NOW - 3600,
			PatchPolicy::fromArray(['regular' => ['patch' => 'manual', 'major' => 'manual']], 'alice', '2026-07-01T00:00:00+00:00'),
			['deck' => 'needs_major'],
		);

		$this->assertEquals((object)['deck' => 'needs_major'], $data['securityHolds']);
		$this->assertTrue($data['advisoriesFresh']);
		$this->assertSame(['patch', 'major'], $data['manualKinds']);
	}

	public function testAnOldOrMissingCheckIsNotFresh(): void {
		$this->assertFalse($this->policies(self::NOW - 12 * 3600 - 1, null, [])['advisoriesFresh']);
		$data = $this->policies(null, null, []);

		$this->assertFalse($data['advisoriesFresh']);
		$this->assertSame([], $data['manualKinds']);
		$this->assertSame('{}', json_encode($data['securityHolds']));
	}
}
