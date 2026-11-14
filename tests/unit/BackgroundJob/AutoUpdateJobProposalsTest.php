<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\BackgroundJob;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\BackgroundJob\AutoUpdateJob;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\AutoUpdate\CandidateSelector;
use OCA\Versioniq\Service\AutoUpdate\ProposalStore;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The job honours declines, approval and the notice period of the proposed
 * updates (autoupdate-proposed-updates, design D6).
 */
final class AutoUpdateJobProposalsTest extends TestCase {
	use BuildsByParameterName;

	private PolicyStore&MockObject $policyStore;
	private InstallerService&MockObject $installerService;
	private CandidateSelector&MockObject $candidateSelector;
	private AutoUpdateSettingsStore&MockObject $settingsStore;
	private ProposalStore&MockObject $proposalStore;

	protected function setUp(): void {
		$this->policyStore = $this->createMock(PolicyStore::class);
		$this->installerService = $this->createMock(InstallerService::class);
		$this->candidateSelector = $this->createMock(CandidateSelector::class);
		$this->settingsStore = $this->createMock(AutoUpdateSettingsStore::class);
		$this->proposalStore = $this->createMock(ProposalStore::class);

		$this->settingsStore->method('isEnabled')->willReturn(true);
		$this->settingsStore->method('getWindow')->willReturn('01:00-05:00');
		$this->installerService->method('isManageableApp')->willReturn(true);
		$this->installerService->method('getAppVersions')->willReturn([
			'availableVersions' => [['version' => '2.3.4'], ['version' => '2.3.5']],
			'hasError' => false,
		]);
	}

	public function testAnApprovedVersionIsInstalled(): void {
		$this->givenPolicy(true, '2.3.4');
		$this->proposalStore->method('isApproved')->with('openregister', '2.3.4')->willReturn(true);

		$this->expectInstall('2.3.4');
		$this->run2am();
	}

	public function testAnApprovalOfAnotherVersionInstallsNothing(): void {
		// 2.3.4 was approved, but the plan now selects 2.3.5.
		$this->givenPolicy(true, '2.3.5');
		$this->proposalStore->method('isApproved')->willReturnCallback(
			static fn (string $appId, string $version): bool => $version === '2.3.4'
		);

		$this->installerService->expects($this->never())->method('installAppVersion');
		$this->run2am();
	}

	public function testADeclinedVersionIsSkippedEvenWithoutApproval(): void {
		$this->givenPolicy(false, '2.3.4');
		$this->proposalStore->method('declineOf')->with('openregister', '2.3.4')
			->willReturn(['by' => 'admin', 'at' => '2026-07-20T00:00:00+00:00', 'reason' => 'breaks the import']);

		$this->installerService->expects($this->never())->method('installAppVersion');
		$this->run2am();
	}

	public function testWithoutNoticeApprovalOrDeclinesTheJobInstallsAsBefore(): void {
		$this->givenPolicy(false, '2.3.4');
		$this->proposalStore->expects($this->never())->method('proposalFor');

		$this->expectInstall('2.3.4');
		$this->run2am();
	}

	public function testWithNoticeTheJobWaitsUntilThePlannedOpening(): void {
		$this->givenPolicy(false, '2.3.4');
		$this->settingsStore->method('getNoticeDays')->willReturn(14);
		$this->proposalStore->method('proposalFor')->willReturn($this->proposal('2.3.4', '2026-07-24T01:00:00+00:00'));

		$this->installerService->expects($this->never())->method('installAppVersion');
		$this->run2am();
	}

	public function testWithNoticeTheJobInstallsOnceThePlannedOpeningHasCome(): void {
		$this->givenPolicy(false, '2.3.4');
		$this->settingsStore->method('getNoticeDays')->willReturn(14);
		$this->proposalStore->method('proposalFor')->willReturn($this->proposal('2.3.4', '2026-07-23T01:00:00+00:00'));

		$this->expectInstall('2.3.4');
		$this->run2am();
	}

	public function testWithNoticeAVersionThatWasNeverAnnouncedWaits(): void {
		// The proposal announced 2.3.4; 2.3.5 has had no notice yet.
		$this->givenPolicy(false, '2.3.5');
		$this->settingsStore->method('getNoticeDays')->willReturn(14);
		$this->proposalStore->method('proposalFor')->willReturn($this->proposal('2.3.4', '2026-07-01T01:00:00+00:00'));

		$this->installerService->expects($this->never())->method('installAppVersion');
		$this->run2am();
	}

	private function givenPolicy(bool $requireApproval, string $candidate): void {
		$this->policyStore->method('all')->willReturn([
			'openregister' => new Policy(Policy::LEVEL_PATCH, 'admin', '2026-07-01T00:00:00+00:00', $requireApproval),
		]);
		$this->candidateSelector->method('select')->willReturn($candidate);
	}

	private function proposal(string $toVersion, string $plannedFor): ProposedUpdate {
		return new ProposedUpdate('openregister', '2.3.0', $toVersion, 'patch', ProposedUpdate::STATUS_IN_NOTICE, '2026-07-09T00:00:00+00:00', $plannedFor, '2026-07-09T00:00:00+00:00');
	}

	private function expectInstall(string $version): void {
		$this->installerService->expects($this->once())
			->method('installAppVersion')
			->with($this->callback(static fn ($request): bool => $request->target->appId === 'openregister' && $request->target->version === $version))
			->willReturn(['statusCode' => 200, 'payload' => ['installedVersion' => $version]]);
	}

	private function run2am(): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-23T02:00:00+00:00'));
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.3.0');

		$job = $this->buildByName(AutoUpdateJob::class, [
			'time' => $time,
			'policyStore' => $this->policyStore,
			'pinStore' => $this->createMock(PinStore::class),
			'installerService' => $this->installerService,
			'appManager' => $appManager,
			'candidateSelector' => $this->candidateSelector,
			'attemptLedger' => $this->createMock(AttemptLedger::class),
			'settingsStore' => $this->settingsStore,
			'proposalStore' => $this->proposalStore,
			'notifier' => $this->createMock(AutoUpdateNotifier::class),
			'logger' => $this->createMock(LoggerInterface::class),
		]);
		(new ReflectionMethod(AutoUpdateJob::class, 'run'))->invoke($job, null);
	}
}
