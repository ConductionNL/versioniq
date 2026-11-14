<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

require_once __DIR__ . '/InMemoryAppConfig.php';

use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\ProposalService;
use OCA\Versioniq\Service\AutoUpdate\ProposalStore;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Service\Settings\StoredSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ProposalService::class)]
#[UsesClass(ProposalStore::class)]
#[UsesClass(ProposedUpdate::class)]
#[UsesClass(AutoUpdateStores::class)]
#[UsesClass(AutoUpdateWindow::class)]
#[UsesClass(CandidateSelector::class)]
#[UsesClass(Policy::class)]
#[UsesClass(Pin::class)]
#[UsesClass(StoredSnapshot::class)]
final class ProposalServiceTest extends TestCase {
	private PolicyStore&MockObject $policyStore;
	private PinStore&MockObject $pinStore;
	private AttemptLedger&MockObject $ledger;
	private AutoUpdateSettingsStore&MockObject $settings;
	private AutoUpdateNotifier&MockObject $notifier;
	private ProposalStore $proposals;
	private ProposalService $service;

	protected function setUp(): void {
		$this->policyStore = $this->createMock(PolicyStore::class);
		$this->pinStore = $this->createMock(PinStore::class);
		$this->ledger = $this->createMock(AttemptLedger::class);
		$this->settings = $this->createMock(AutoUpdateSettingsStore::class);
		$this->notifier = $this->createMock(AutoUpdateNotifier::class);
		$this->settings->method('getWindow')->willReturn('01:00-05:00');
		$this->settings->method('getTimeZoneName')->willReturn('UTC');

		$this->proposals = new ProposalStore((new InMemoryAppConfig())->mock($this, $this->createMock(...)), $this->createMock(LoggerInterface::class));
		$this->service = new ProposalService(
			new AutoUpdateStores($this->policyStore, $this->pinStore, $this->settings, $this->ledger, $this->proposals),
			$this->proposals,
			new CandidateSelector(),
			$this->notifier,
		);
	}

	public function testAProposalIsWorkedOutFromTheSweepsListsAlone(): void {
		$this->policies(['openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00')]);

		$this->service->refresh(['openregister' => $this->listing('2.3.0', ['2.3.4' => 'Fixes the import', '2.4.0' => 'New'])], $this->at('2026-10-01T10:00:00'));

		$stored = $this->proposals->read();
		$proposal = $stored['proposals']['openregister'];
		$this->assertSame('2.3.0', $proposal->fromVersion);
		$this->assertSame('2.3.4', $proposal->toVersion);
		$this->assertSame('patch', $proposal->kind);
		$this->assertSame(ProposedUpdate::STATUS_PLANNED, $proposal->status);
		$this->assertSame('Fixes the import', $proposal->releaseNotes);
		// The next opening of 01:00-05:00 after 10:00 is the next night.
		$this->assertSame('2026-10-02T01:00:00+00:00', $proposal->plannedFor);
		$this->assertSame($this->at('2026-10-01T10:00:00')->getTimestamp(), $stored['computedAt']);
	}

	public function testAppsWithoutAProposalStoreTheReason(): void {
		$this->policies([
			'calendar' => new Policy(Policy::LEVEL_MINOR, 'admin', '2026-09-01T00:00:00+00:00'),
			'deck' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00'),
			'forms' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00'),
			'mail' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00'),
			'notes' => new Policy(Policy::LEVEL_NONE, 'admin', '2026-09-01T00:00:00+00:00'),
		]);
		$this->pinStore->method('get')->willReturnCallback(
			static fn (string $appId): ?Pin => $appId === 'calendar' ? new Pin('4.0.0', 'admin', '2026-09-01T00:00:00+00:00') : null
		);
		$this->ledger->method('hasAttempted')->willReturnCallback(
			static fn (string $appId, string $version): bool => $appId === 'mail' && $version === '3.1.1'
		);

		$this->service->refresh([
			'calendar' => $this->listing('4.0.0', ['4.1.0' => '']),
			'deck' => $this->listing('1.9.0', ['2.0.0' => '']),
			'forms' => ['installedVersion' => '4.3.0', 'versions' => null],
			'mail' => $this->listing('3.1.0', ['3.1.1' => '']),
			'notes' => $this->listing('1.0.0', ['1.0.1' => '']),
		], $this->at('2026-10-01T10:00:00'));

		$stored = $this->proposals->read();
		$this->assertSame([], $stored['proposals']);
		$this->assertSame([
			'calendar' => ProposalService::REASON_PINNED,
			'deck' => ProposalService::REASON_NO_CANDIDATE,
			'forms' => ProposalService::REASON_SOURCE_ERROR,
			'mail' => ProposalService::REASON_BLOCKED,
		], $stored['reasons']);
	}

	public function testAPlannedProposalMovesToANewerVersion(): void {
		$this->policies(['openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00')]);
		$this->service->refresh(['openregister' => $this->listing('2.3.0', ['2.3.4' => ''])], $this->at('2026-10-01T10:00:00'));

		$this->service->refresh(['openregister' => $this->listing('2.3.0', ['2.3.4' => '', '2.3.5' => 'Newer'])], $this->at('2026-10-01T16:00:00'));

		$proposal = $this->proposals->proposalFor('openregister');
		$this->assertSame('2.3.5', $proposal?->toVersion);
		$this->assertSame('2026-10-01T16:00:00+00:00', $proposal?->proposedAt);
	}

	public function testAnAnnouncedProposalKeepsItsVersionAndItsClock(): void {
		$this->settings->method('getNoticeDays')->willReturn(14);
		$this->settings->method('getNoticeGroup')->willReturn('functioneel-beheer');
		$this->policies(['forms' => new Policy(Policy::LEVEL_MINOR, 'admin', '2026-09-01T00:00:00+00:00')]);
		$this->notifier->expects($this->once())->method('notifyPlanned')
			->with($this->callback(static fn (ProposedUpdate $p): bool => $p->toVersion === '4.4.0' && $p->plannedFor === '2026-10-16T01:00:00+00:00'), 'functioneel-beheer');

		$this->service->refresh(['forms' => $this->listing('4.3.0', ['4.4.0' => 'Notes'])], $this->at('2026-10-01T10:00:00'));
		$first = $this->proposals->proposalFor('forms');
		$this->assertSame(ProposedUpdate::STATUS_IN_NOTICE, $first?->status);
		$this->assertSame('2026-10-01T10:00:00+00:00', $first?->announcedAt);

		// 4.4.1 appears on 10 October: no new announcement, same version and date.
		$this->service->refresh(['forms' => $this->listing('4.3.0', ['4.4.0' => 'Notes', '4.4.1' => 'Newer'])], $this->at('2026-10-10T10:00:00'));

		$kept = $this->proposals->proposalFor('forms');
		$this->assertSame('4.4.0', $kept?->toVersion);
		$this->assertSame('2026-10-16T01:00:00+00:00', $kept?->plannedFor);
		$this->assertSame('2026-10-01T10:00:00+00:00', $kept?->announcedAt);
	}

	public function testOnceInstalledTheNextVersionBecomesTheProposal(): void {
		$this->settings->method('getNoticeDays')->willReturn(14);
		$this->policies(['forms' => new Policy(Policy::LEVEL_MINOR, 'admin', '2026-09-01T00:00:00+00:00')]);
		$this->notifier->expects($this->exactly(2))->method('notifyPlanned');

		$this->service->refresh(['forms' => $this->listing('4.3.0', ['4.4.0' => ''])], $this->at('2026-10-01T10:00:00'));
		$this->service->refresh(['forms' => $this->listing('4.4.0', ['4.4.0' => '', '4.4.1' => ''])], $this->at('2026-10-16T10:00:00'));

		$this->assertSame('4.4.1', $this->proposals->proposalFor('forms')?->toVersion);
	}

	public function testRequiringApprovalAwaitsItAndAnApprovalOfThatVersionShows(): void {
		$this->policies([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00', true),
			'deck' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00', true),
		]);
		$this->proposals->approve('openregister', '2.3.4', 'alice', '2026-09-30T00:00:00+00:00');

		$this->service->refresh([
			'openregister' => $this->listing('2.3.0', ['2.3.4' => '']),
			'deck' => $this->listing('1.9.0', ['1.9.3' => '']),
		], $this->at('2026-10-01T10:00:00'));

		$approved = $this->proposals->proposalFor('openregister')?->toArray();
		$this->assertSame(ProposedUpdate::STATUS_APPROVED, $approved['status'] ?? null);
		$this->assertSame('alice', $approved['approvedBy'] ?? null);
		$this->assertSame(ProposedUpdate::STATUS_AWAITING_APPROVAL, $this->proposals->proposalFor('deck')?->status);
	}

	public function testADeclinedVersionIsListedAsDeclinedAndNotAnnounced(): void {
		$this->settings->method('getNoticeDays')->willReturn(14);
		$this->policies(['deck' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00')]);
		$this->proposals->decline('deck', '1.9.3', 'alice', '2026-09-30T00:00:00+00:00', 'breaks our board import');
		$this->notifier->expects($this->never())->method('notifyPlanned');

		$this->service->refresh(['deck' => $this->listing('1.9.0', ['1.9.3' => ''])], $this->at('2026-10-01T10:00:00'));

		$declined = $this->proposals->proposalFor('deck')?->toArray();
		$this->assertSame(ProposedUpdate::STATUS_DECLINED, $declined['status'] ?? null);
		$this->assertSame('breaks our board import', $declined['declineReason'] ?? null);
		$this->assertArrayHasKey('plannedFor', $declined);
		$this->assertNull($declined['plannedFor']);
	}

	public function testAnAppTheSweepDidNotReachHasNoEntry(): void {
		$this->policies(['openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-09-01T00:00:00+00:00')]);

		$this->service->refresh([], $this->at('2026-10-01T10:00:00'));

		$this->assertSame(['proposals' => [], 'reasons' => []], array_intersect_key($this->proposals->read(), ['proposals' => 1, 'reasons' => 1]));
	}

	/**
	 * @param array<string, Policy> $policies
	 */
	private function policies(array $policies): void {
		$this->policyStore->method('all')->willReturn($policies);
	}

	/**
	 * @param array<string, string> $notes
	 * @return array{installedVersion: string, versions: list<array<string, mixed>>}
	 */
	private function listing(string $installed, array $notes): array {
		$versions = [];
		foreach ($notes as $version => $changelog) {
			$versions[] = ['version' => (string)$version, 'changelog' => $changelog, 'serverCompatible' => true];
		}

		return ['installedVersion' => $installed, 'versions' => $versions];
	}

	private function at(string $time): \DateTimeImmutable {
		return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
	}
}
