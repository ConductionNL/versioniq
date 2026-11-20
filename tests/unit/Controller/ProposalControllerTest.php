<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ProposalController;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\ProposalStore;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProposalController::class)]
#[UsesClass(ProposedUpdate::class)]
#[UsesClass(ApiCaller::class)]
#[UsesClass(AuditSubject::class)]
#[UsesClass(AuditedVersions::class)]
final class ProposalControllerTest extends TestCase {
	private ProposalStore&MockObject $store;
	private AuditLogger&MockObject $audit;

	protected function setUp(): void {
		$this->store = $this->createMock(ProposalStore::class);
		$this->audit = $this->createMock(AuditLogger::class);
	}

	/**
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function routes(): array {
		return [
			'index' => ['index', []],
			'approve' => ['approve', ['openregister']],
			'decline' => ['decline', ['openregister']],
			'withdraw' => ['withdraw', ['openregister', '2.3.4']],
		];
	}

	/**
	 * @param list<string> $args
	 */
	#[DataProvider('routes')]
	public function testANonAdminIsRefusedAndNothingIsRead(string $method, array $args): void {
		$this->store->expects($this->never())->method($this->anything());
		$this->audit->expects($this->never())->method('record');

		$response = $this->controller(['version' => '2.3.4'], false)->$method(...$args);

		$this->assertSame(403, $response->getStatus());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function decidingRoutes(): array {
		return ['approve' => ['approve'], 'decline' => ['decline'], 'withdraw' => ['withdraw']];
	}

	#[DataProvider('decidingRoutes')]
	public function testEveryDecidingRouteIsPasswordConfirmed(string $method): void {
		$attributes = (new \ReflectionMethod(ProposalController::class, $method))->getAttributes(PasswordConfirmationRequired::class);

		$this->assertCount(1, $attributes);
	}

	public function testIndexReturnsTheStoredProposalsInPlannedOrderWithTheComputedTime(): void {
		$this->store->method('read')->willReturn([
			'proposals' => [
				'zeta' => $this->proposal('zeta', '1.0.1', '2026-10-03T01:00:00+00:00'),
				'deck' => $this->proposal('deck', '1.9.3', null),
				'alpha' => $this->proposal('alpha', '2.0.1', '2026-10-02T01:00:00+00:00'),
			],
			'reasons' => ['calendar' => 'pinned'],
			'computedAt' => 1790000000,
		]);

		$data = $this->controller()->index()->getData();

		$this->assertSame(['alpha', 'zeta', 'deck'], array_column($data['proposals'], 'appId'));
		$this->assertSame([['appId' => 'calendar', 'reason' => 'pinned']], $data['reasons']);
		$this->assertSame('2026-09-21T14:13:20+00:00', $data['computedAt']);
	}

	public function testIndexBeforeAnyProposalStepSaysNeverComputed(): void {
		$this->store->method('read')->willReturn(['proposals' => [], 'reasons' => [], 'computedAt' => null]);

		$this->assertNull($this->controller()->index()->getData()['computedAt']);
	}

	public function testApproveRecordsTheExactVersionAndAudits(): void {
		$this->store->method('proposalFor')->willReturn($this->proposal('openregister', '2.3.4', '2026-10-02T01:00:00+00:00'));
		$this->store->expects($this->once())->method('approve')->with('openregister', '2.3.4', 'alice', '2026-10-01T09:00:00+00:00');
		$this->store->expects($this->once())->method('replace')
			->with($this->callback(static fn (ProposedUpdate $p): bool => $p->status === ProposedUpdate::STATUS_APPROVED && $p->decision === ['by' => 'alice', 'at' => '2026-10-01T09:00:00+00:00']));
		$this->audit->expects($this->once())->method('record')->with(
			new AuditSubject('alice', 'openregister', 'proposal_approve'),
			new AuditedVersions('2.3.0', '2.3.4', null),
			AuditLogger::STATUS_SUCCESS,
			null,
		);

		$response = $this->controller(['version' => '2.3.4'])->approve('openregister');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('alice', $response->getData()['proposal']['approvedBy']);
	}

	public function testApprovingAnotherVersionThanTheProposalIs404(): void {
		$this->store->method('proposalFor')->willReturn($this->proposal('openregister', '2.3.4', null));
		$this->store->expects($this->never())->method('approve');
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(404, $this->controller(['version' => '2.4.0'])->approve('openregister')->getStatus());
	}

	public function testApprovingWithoutAProposalIs404AndWithoutAVersion400(): void {
		$this->store->method('proposalFor')->willReturn(null);

		$this->assertSame(404, $this->controller(['version' => '2.3.4'])->approve('openregister')->getStatus());
		$this->assertSame(400, $this->controller([])->approve('openregister')->getStatus());
	}

	public function testDeclineRecordsTheReasonAndAudits(): void {
		$this->store->method('proposalFor')->willReturn($this->proposal('deck', '1.9.3', '2026-10-02T01:00:00+00:00'));
		$this->store->expects($this->once())->method('decline')->with('deck', '1.9.3', 'alice', '2026-10-01T09:00:00+00:00', 'breaks our board import');
		$this->store->expects($this->once())->method('replace')
			->with($this->callback(static fn (ProposedUpdate $p): bool => $p->status === ProposedUpdate::STATUS_DECLINED && $p->plannedFor === null));
		$this->audit->expects($this->once())->method('record')->with(
			new AuditSubject('alice', 'deck', 'proposal_decline'),
			new AuditedVersions('2.3.0', '1.9.3', null),
			AuditLogger::STATUS_SUCCESS,
			'breaks our board import',
		);

		$response = $this->controller(['version' => '1.9.3', 'reason' => ' breaks our board import '])->decline('deck');

		$this->assertSame('breaks our board import', $response->getData()['proposal']['declineReason']);
	}

	public function testDecliningAVersionWithoutAProposalIs404(): void {
		$this->store->method('proposalFor')->willReturn(null);
		$this->store->expects($this->never())->method('decline');

		$this->assertSame(404, $this->controller(['version' => '1.9.3'])->decline('deck')->getStatus());
	}

	public function testWithdrawRemovesTheDeclineAndAudits(): void {
		$declined = $this->proposal('deck', '1.9.3', null)->with(ProposedUpdate::STATUS_DECLINED, null, null, ['by' => 'alice', 'at' => 'x', 'reason' => 'r']);
		$this->store->expects($this->once())->method('withdrawDecline')->with('deck', '1.9.3')->willReturn(true);
		$this->store->method('proposalFor')->willReturn($declined);
		$this->store->expects($this->once())->method('replace')
			->with($this->callback(static fn (ProposedUpdate $p): bool => $p->status === ProposedUpdate::STATUS_PLANNED && $p->decision === null));
		$this->audit->expects($this->once())->method('record')->with(
			new AuditSubject('alice', 'deck', 'proposal_withdraw'),
			new AuditedVersions('2.3.0', '1.9.3', null),
			AuditLogger::STATUS_SUCCESS,
		);

		$response = $this->controller()->withdraw('deck', '1.9.3');

		$this->assertSame(['appId' => 'deck', 'version' => '1.9.3', 'withdrawn' => true], $response->getData());
	}

	public function testWithdrawingAVersionThatIsNotDeclinedIs404(): void {
		$this->store->method('withdrawDecline')->willReturn(false);
		$this->audit->expects($this->never())->method('record');

		$this->assertSame(404, $this->controller()->withdraw('deck', '1.9.3')->getStatus());
	}

	private function proposal(string $appId, string $toVersion, ?string $plannedFor): ProposedUpdate {
		return new ProposedUpdate($appId, '2.3.0', $toVersion, 'patch', ProposedUpdate::STATUS_PLANNED, '2026-09-30T00:00:00+00:00', $plannedFor);
	}

	/**
	 * @param array<string, string> $params
	 */
	private function controller(array $params = [], bool $admin = true): ProposalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => $params[$name] ?? $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-01T09:00:00+00:00'));

		return new ProposalController('versioniq', $request, new ApiCaller($session, $groups, $time), $this->store, $this->audit);
	}
}
