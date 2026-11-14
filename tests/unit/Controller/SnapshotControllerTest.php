<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\SnapshotController;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\RollbackRunLog;
use OCA\Versioniq\Service\Snapshot\SnapshotPlans;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The snapshot endpoints (install-bulk-rollback design D2-D4): admin-only,
 * the date pick, taking and deleting a snapshot, the plan and the run log
 * with its audit rows.
 *
 * @covers \OCA\Versioniq\Controller\SnapshotController
 * @uses \OCA\Versioniq\Service\Snapshot\SnapshotStore
 * @uses \OCA\Versioniq\Service\Snapshot\SnapshotTaker
 * @uses \OCA\Versioniq\Service\Snapshot\RollbackRunLog
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class SnapshotControllerTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];

	/** @var list<array{0: AuditSubject, 1: string, 2: ?string}> */
	private array $audited = [];

	private SnapshotStore $store;

	/** @var list<InstanceSnapshot> */
	private array $planned = [];

	private function controller(bool $admin = true, bool $signedIn = true): SnapshotController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy = false): bool {
			$this->config[$key] = $value;

			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$this->store = new SnapshotStore($appConfig, $this->createMock(LoggerInterface::class));

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $status, $message];
		});
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			['id' => 'openregister', 'installedVersion' => '2.3.4', 'state' => 'enabled'],
		]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-26T12:00:00', new \DateTimeZone('UTC')));
		$plans = $this->createMock(SnapshotPlans::class);
		$plans->method('planFor')->willReturnCallback(function (InstanceSnapshot $snapshot): array {
			$this->planned[] = $snapshot;

			return [['appId' => 'openregister', 'kind' => 'downgrade', 'from' => '2.3.4', 'to' => '2.3.0', 'sourceId' => null, 'pinnedTo' => null]];
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SnapshotController(
			'versioniq',
			$this->createMock(IRequest::class),
			$this->store,
			new SnapshotTaker($installer, $this->store, $audit, $time),
			$plans,
			new RollbackRunLog($audit),
			$groups,
			$session,
		);
	}

	private function seed(string $id, string $takenAt): void {
		$this->store->save(new InstanceSnapshot(['id' => $id, 'takenAt' => $takenAt, 'takenBy' => 'system', 'reason' => 'daily'], [
			'openregister' => ['version' => '2.3.0', 'sourceId' => null, 'enabled' => true],
		]));
	}

	public function testEveryEndpointRefusesANonAdminAndChangesNothing(): void {
		foreach ([[false, true], [true, false]] as [$admin, $signedIn]) {
			$controller = $this->controller($admin, $signedIn);
			$this->seed('s20', '2026-09-20T10:00:00+00:00');
			$this->audited = [];

			$this->assertSame(403, $controller->index()->getStatus());
			$this->assertSame(403, $controller->create('x')->getStatus());
			$this->assertSame(403, $controller->destroy('s20')->getStatus());
			$this->assertSame(403, $controller->plan('s20')->getStatus());
			$this->assertSame(403, $controller->runLog('s20', 'start', 1)->getStatus());
			$this->assertCount(1, $this->store->index());
			$this->assertSame([], $this->audited);
			$this->assertSame([], $this->planned);
		}
	}

	public function testADatePicksTheNewestSnapshotOnOrBeforeIt(): void {
		$controller = $this->controller();
		$this->seed('s20', '2026-09-20T10:00:00+00:00');
		$this->seed('s25', '2026-09-25T10:00:00+00:00');

		$response = $controller->index('2026-09-23');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('s20', $response->getData()['picked']['id']);
		$this->assertSame(['s25', 's20'], array_column($response->getData()['snapshots'], 'id'));
		$this->assertNull($controller->index('2026-09-19')->getData()['picked']);
		$this->assertNull($controller->index()->getData()['picked']);
		$this->assertSame(400, $controller->index('23 September')->getStatus());
	}

	public function testANamedSnapshotIsTakenAndAudited(): void {
		$controller = $this->controller();

		$response = $controller->create('Before the October upgrade');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('Before the October upgrade', $response->getData()['name']);
		$this->assertSame('manual', $response->getData()['reason']);
		$this->assertSame(1, $response->getData()['appCount']);
		$this->assertSame('Before the October upgrade', $this->store->index()[0]['name']);
		$this->assertCount(1, $this->audited);
		$this->assertSame(['alice', AuditLogger::OPERATION_SNAPSHOT], [$this->audited[0][0]->actorUid, $this->audited[0][0]->operation]);
	}

	public function testThePageMayAskForABeforeRollbackSnapshotButNotAJobReason(): void {
		$controller = $this->controller();

		$this->assertSame('before_rollback', $controller->create('', 'before_rollback')->getData()['reason']);
		$this->assertSame(400, $controller->create('', 'daily')->getStatus());
		$this->assertCount(1, $this->store->index());
	}

	public function testTheNamedCapAnswersConflict(): void {
		$controller = $this->controller();
		for ($i = 0; $i < SnapshotStore::NAMED_CAP; $i++) {
			$this->store->save(new InstanceSnapshot(['id' => 'n' . $i, 'takenAt' => '2026-09-01T00:00:00+00:00', 'takenBy' => 'alice', 'reason' => 'manual', 'name' => 'n' . $i], []));
		}

		$response = $controller->create('one more');

		$this->assertSame(409, $response->getStatus());
		$this->assertCount(SnapshotStore::NAMED_CAP, $this->store->index());
		$this->assertSame([], $this->audited);
	}

	public function testDeleteRemovesASnapshotAndAnswersNotFoundForAnUnknownOne(): void {
		$controller = $this->controller();
		$this->seed('s20', '2026-09-20T10:00:00+00:00');

		$this->assertSame(['deleted' => 's20'], $controller->destroy('s20')->getData());
		$this->assertSame([], $this->store->index());
		$this->assertSame(404, $controller->destroy('s20')->getStatus());
	}

	public function testThePlanIsBuiltFromTheStoredSnapshot(): void {
		$controller = $this->controller();
		$this->seed('s20', '2026-09-20T10:00:00+00:00');

		$response = $controller->plan('s20');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('s20', $response->getData()['snapshot']['id']);
		$this->assertSame('downgrade', $response->getData()['steps'][0]['kind']);
		$this->assertSame('s20', $this->planned[0]->id);
		$this->assertSame(404, $controller->plan('missing')->getStatus());
	}

	public function testTheRunLogWritesTheStartAndEndRowsWithTheirCounts(): void {
		$controller = $this->controller();
		$this->seed('s20', '2026-09-20T10:00:00+00:00');
		$this->audited = [];

		$this->assertSame(['recorded' => 'start'], $controller->runLog('s20', 'start', 2)->getData());
		$this->assertSame(['recorded' => 'end'], $controller->runLog('s20', 'end', 0, 2, 0, 0)->getData());

		$this->assertSame([
			['alice', 'bulk_rollback', AuditLogger::STATUS_SUCCESS, 'Rollback to snapshot s20 started: 2 steps'],
			['alice', 'bulk_rollback', AuditLogger::STATUS_SUCCESS, 'Rollback to snapshot s20 finished: 2 succeeded, 0 failed, 0 skipped'],
		], array_map(static fn (array $row): array => [$row[0]->actorUid, $row[0]->operation, $row[1], $row[2]], $this->audited));
	}

	public function testTheRunLogRefusesAnUnknownSnapshotPhaseOrNegativeCount(): void {
		$controller = $this->controller();
		$this->seed('s20', '2026-09-20T10:00:00+00:00');
		$this->audited = [];

		$this->assertSame(404, $controller->runLog('missing', 'start', 1)->getStatus());
		$this->assertSame(400, $controller->runLog('s20', 'middle')->getStatus());
		$this->assertSame(400, $controller->runLog('s20', 'end', 0, 1, -1)->getStatus());
		$this->assertSame([], $this->audited);
	}
}
