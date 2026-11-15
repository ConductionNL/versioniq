<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\AdvisoryTriageController;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Advisory\AdvisoryOwners;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryTriageService;
use OCA\Versioniq\Service\Advisory\AdvisoryTriageStore;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Versioniq\Controller\AdvisoryTriageController
 * @uses \OCA\Versioniq\Service\AdminNotificationServices
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryOwners
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryTriageService
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryTriageStore
 * @uses \OCA\Versioniq\Service\Advisory\Dismissal
 * @uses \OCA\Versioniq\Service\Advisory\TriageRefusal
 * @uses \OCA\Versioniq\Service\Advisory\TriageTarget
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 */
final class AdvisoryTriageControllerTest extends TestCase {
	/** @var array<string, string> In-memory app config. */
	private array $config = [];

	/** @var list<array{0: AuditSubject, 1: AuditedVersions, 2: string, 3: ?string}> */
	private array $audits = [];

	/** @var list<string> The uids notified. */
	private array $notified = [];

	/** @var list<array<string, mixed>> The subject parameters notified. */
	private array $subjects = [];

	/**
	 * A controller over a snapshot holding GHSA-1 on openregister 2.3.0 and
	 * GHSA-s on the server, with admins alice and bob, non-admin carol, and a
	 * group `ops` holding bob and carol.
	 *
	 * @param array<string, string> $params The request body.
	 */
	private function controller(array $params, string $caller = 'alice'): AdvisoryTriageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $name): mixed => $params[$name] ?? null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->groupManager();

		return new AdvisoryTriageController('versioniq', $request, $this->service($groupManager), $groupManager, $session);
	}

	private function groupManager(): IGroupManager {
		$users = [];
		foreach (['bob', 'carol'] as $uid) {
			$member = $this->createMock(IUser::class);
			$member->method('getUID')->willReturn($uid);
			$users[] = $member;
		}
		$ops = $this->createMock(IGroup::class);
		$ops->method('getUsers')->willReturn($users);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['alice', 'bob'], true));
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'ops');
		$groupManager->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'ops' ? $ops : null);

		return $groupManager;
	}

	private function service(IGroupManager $groupManager): AdvisoryTriageService {
		$results = $this->createMock(AdvisoryResultStore::class);
		$results->method('read')->willReturn(['checkedAt' => 1_790_000_000, 'advisories' => [
			'openregister' => ['appId' => 'openregister', 'installedVersion' => '2.3.0', 'state' => 'pinned-to-vulnerable', 'advisories' => [['id' => 'GHSA-1', 'severity' => 'high', 'summary' => 'x']]],
			':server' => ['appId' => ':server', 'installedVersion' => '32.0.1', 'state' => 'advisory-available', 'advisories' => [['id' => 'GHSA-s', 'severity' => 'low', 'summary' => 'y']]],
		]]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;

			return true;
		});

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audits[] = [$subject, $versions, $status, $message];
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);
		$time->method('getDateTime')->willReturn(new \DateTime('@1790000000'));

		return new AdvisoryTriageService(
			$results,
			new AdvisoryTriageStore($appConfig, $this->createMock(LoggerInterface::class)),
			new AdvisoryOwners(new AdminNotificationServices($this->notifications(), $groupManager, $time, $this->createMock(IURLGenerator::class)), $this->createMock(LoggerInterface::class)),
			$audit,
			$time,
		);
	}

	private function notifications(): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(function (): INotification {
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setDateTime', 'setObject', 'setLink'] as $setter) {
				$notification->method($setter)->willReturnSelf();
			}
			$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification): INotification {
				$this->notified[] = $uid;

				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters) use ($notification): INotification {
				$this->subjects[] = ['subject' => $subject] + $parameters;

				return $notification;
			});

			return $notification;
		});

		return $manager;
	}

	/** @return array<string, mixed> */
	private function stored(): array {
		return json_decode($this->config['advisory.triage'] ?? '{}', true);
	}

	public function testANonAdminIsRefusedOnEveryRouteAndNothingIsStored(): void {
		$params = ['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'reason' => 'not_used', 'assignee' => 'user:bob'];

		foreach (['dismiss', 'reopen', 'assign'] as $route) {
			$response = $this->controller($params, 'carol')->$route();
			$this->assertSame(403, $response->getStatus(), $route);
			$this->assertSame(['message' => 'Forbidden'], $response->getData());
		}

		$this->assertSame([], $this->config);
		$this->assertSame([], $this->audits);
	}

	public function testAnAdvisoryNotInTheSnapshotIs404(): void {
		$wrongApp = $this->controller(['appId' => 'deck', 'advisoryId' => 'GHSA-1', 'reason' => 'not_used'])->dismiss();
		$wrongId = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-s'])->reopen();
		$assign = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-9', 'assignee' => 'user:bob'])->assign();

		$this->assertSame([404, 404, 404], [$wrongApp->getStatus(), $wrongId->getStatus(), $assign->getStatus()]);
		$this->assertSame(['message' => 'This advisory is not in the stored advisory check for this app.'], $wrongApp->getData());
		$this->assertSame([], $this->config);
		$this->assertSame([], $this->audits);
	}

	public function testAPlannedFixWithoutAnEndDateIs400AndNothingIsStored(): void {
		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'reason' => 'fix_planned'])->dismiss();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'A planned fix needs an end date.'], $response->getData());
		$this->assertSame([], $this->config);
		$this->assertSame([], $this->audits);
	}

	public function testAnUnknownReasonIs400(): void {
		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'reason' => 'because'])->dismiss();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->config);
	}

	public function testDismissStoresTheDecisionAndWritesOneAuditEntry(): void {
		$response = $this->controller(['appId' => ':server', 'advisoryId' => 'GHSA-s', 'reason' => 'not_used', 'comment' => ' Public shares are off. '])->dismiss();

		$dismissal = [
			'reason' => 'not_used',
			'comment' => 'Public shares are off.',
			'by' => 'alice',
			'at' => '2026-09-21T14:13:20+00:00',
			'until' => null,
			'installedVersion' => '32.0.1',
		];
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['triage' => [':server|GHSA-s' => ['dismissal' => $dismissal]]], $response->getData());
		$this->assertSame([':server|GHSA-s' => ['dismissal' => $dismissal]], $this->stored());

		$this->assertCount(1, $this->audits);
		[$subject, $versions, $status, $message] = $this->audits[0];
		$this->assertSame(['alice', ':server', 'advisory_dismiss'], [$subject->actorUid, $subject->appId, $subject->operation]);
		$this->assertSame([null, '32.0.1'], [$versions->fromVersion, $versions->toVersion]);
		$this->assertSame(['success', 'GHSA-s: not_used: Public shares are off.'], [$status, $message]);
	}

	public function testReopenDropsTheDismissalAndWritesOneAuditEntry(): void {
		$this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'reason' => 'mitigated'])->dismiss();
		$this->audits = [];

		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1'])->reopen();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['triage' => []], $response->getData());
		$this->assertSame([], $this->stored());
		$this->assertCount(1, $this->audits);
		$this->assertSame('advisory_reopen', $this->audits[0][0]->operation);
		$this->assertSame('GHSA-1', $this->audits[0][3]);
	}

	public function testANonAdminUserCannotBeTheOwner(): void {
		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'assignee' => 'user:carol'])->assign();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->config);
		$this->assertSame([], $this->notified);
		$this->assertSame([], $this->audits);
	}

	public function testAssigningToAnAdminNotifiesThemAndWritesOneAuditEntry(): void {
		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'assignee' => 'user:bob'])->assign();

		$entry = ['assignee' => ['type' => 'user', 'id' => 'bob'], 'assignedBy' => 'alice', 'assignedAt' => '2026-09-21T14:13:20+00:00'];
		$this->assertSame(['triage' => ['openregister|GHSA-1' => $entry]], $response->getData());
		$this->assertSame(['bob'], $this->notified);
		$this->assertSame([['subject' => 'advisory_assigned', 'app' => 'openregister', 'advisory' => 'GHSA-1', 'by' => 'alice']], $this->subjects);
		$this->assertCount(1, $this->audits);
		$this->assertSame(['advisory_assign', 'GHSA-1: user:bob', '2.3.0'], [$this->audits[0][0]->operation, $this->audits[0][3], $this->audits[0][1]->toVersion]);
	}

	public function testAGroupAssignmentNotifiesOnlyItsAdminMembers(): void {
		$this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'assignee' => 'group:ops'])->assign();

		$this->assertSame(['bob'], $this->notified);
		$this->assertSame(['type' => 'group', 'id' => 'ops'], $this->stored()['openregister|GHSA-1']['assignee']);
	}

	public function testUnassigningKeepsTheDismissalAndNotifiesNobody(): void {
		$this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'reason' => 'tolerable_risk'])->dismiss();
		$this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'assignee' => 'user:bob'])->assign();
		$this->notified = [];

		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'assignee' => ''])->assign();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['dismissal'], array_keys($this->stored()['openregister|GHSA-1']));
		$this->assertSame([], $this->notified);
		$this->assertSame('GHSA-1: unassigned', $this->audits[2][3]);
	}

	public function testAnUnknownGroupIs400(): void {
		$response = $this->controller(['appId' => 'openregister', 'advisoryId' => 'GHSA-1', 'assignee' => 'group:nobody'])->assign();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->config);
	}
}
