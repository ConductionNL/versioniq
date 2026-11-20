<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lifecycle\AppReplacer;
use OCA\Versioniq\Service\Lifecycle\SuccessorList;
use OCP\App\IAppManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */
#[CoversClass(AppReplacer::class)]
#[UsesClass(AuditedVersions::class)]
#[UsesClass(AuditSubject::class)]
#[UsesClass(InstallOptions::class)]
#[UsesClass(InstallRequest::class)]
#[UsesClass(InstallTarget::class)]
#[UsesClass(PinChoice::class)]
final class AppReplacerTest extends TestCase {
	private const OFFER = ['from' => 'oldname', 'to' => 'newname', 'source' => 'appstore', 'custom' => true, 'version' => '1.0.0'];

	private SuccessorList&MockObject $successors;
	private InstallerService&MockObject $installer;
	private IAppManager&MockObject $appManager;
	private AuditLogger&MockObject $audit;

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		$this->successors = $this->createMock(SuccessorList::class);
		$this->installer = $this->createMock(InstallerService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->audit = $this->createMock(AuditLogger::class);
		$this->appManager->method('getAppVersion')->willReturn('3.1.0');
		$this->appManager->method('enableApp')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'enable ' . $appId;
		});
		$this->appManager->method('disableApp')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'disable ' . $appId;
		});
	}

	private function replacer(): AppReplacer {
		return new AppReplacer($this->successors, $this->installer, $this->appManager, $this->audit);
	}

	public function testASuccessfulInstallEnablesTheSuccessorThenDisablesTheOldAppAndAuditsIt(): void {
		$this->successors->method('offerFor')->with('oldname')->willReturn(self::OFFER);
		$this->installer->expects($this->once())->method('installAppVersion')
			->with($this->callback(static fn (InstallRequest $request): bool => $request->target->appId === 'newname'
				&& $request->target->version === '1.0.0'
				&& $request->target->sourceOverride === 'appstore'
				&& $request->options->dryRun === false
				&& $request->options->allowDowngrade === false
				&& $request->pinChoice->pinRequested === false))
			->willReturnCallback(function (): array {
				$this->calls[] = 'install newname';

				return ['statusCode' => 200, 'payload' => ['message' => 'installed']];
			});
		$this->audit->expects($this->once())->method('record')->with(
			$this->callback(static fn (AuditSubject $subject): bool => $subject->actorUid === 'admin' && $subject->appId === 'oldname' && $subject->operation === 'replace'),
			$this->callback(static fn (AuditedVersions $versions): bool => $versions->fromVersion === '3.1.0' && $versions->sourceId === 'appstore'),
			AuditLogger::STATUS_SUCCESS,
			'Replaced with newname 1.0.0; its data was kept.',
		);

		$outcome = $this->replacer()->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'newname');

		$this->assertSame(200, $outcome['statusCode']);
		$this->assertTrue($outcome['payload']['replaced']);
		$this->assertSame('newname', $outcome['payload']['to']);
		$this->assertSame(['install newname', 'enable newname', 'disable oldname'], $this->calls);
	}

	public function testAFailedSuccessorInstallLeavesTheOldAppEnabled(): void {
		$this->successors->method('offerFor')->willReturn(self::OFFER);
		$this->installer->method('installAppVersion')->willReturn(['statusCode' => 409, 'payload' => ['message' => 'SHA-256 mismatch']]);
		$this->appManager->expects($this->never())->method('disableApp');
		$this->appManager->expects($this->never())->method('enableApp');
		$this->audit->expects($this->never())->method('record');

		$outcome = $this->replacer()->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'newname');

		$this->assertSame(409, $outcome['statusCode']);
		$this->assertFalse($outcome['payload']['replaced']);
		$this->assertSame(['message' => 'SHA-256 mismatch'], $outcome['payload']['install']);
	}

	public function testNothingIsInstalledWithoutAMatchingOffer(): void {
		$this->successors->method('offerFor')->willReturnOnConsecutiveCalls(null, self::OFFER);
		$this->installer->expects($this->never())->method('installAppVersion');

		$this->assertSame(404, $this->replacer()->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'newname')['statusCode']);
		$this->assertSame(404, $this->replacer()->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'othername')['statusCode']);
	}

	public function testAFailingSwitchOverIsAuditedAsAFailure(): void {
		$this->successors->method('offerFor')->willReturn(self::OFFER);
		$this->installer->method('installAppVersion')->willReturn(['statusCode' => 200, 'payload' => []]);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('enableApp')->willThrowException(new \RuntimeException('cannot enable'));
		$appManager->expects($this->never())->method('disableApp');
		$this->audit->expects($this->once())->method('record')->with(
			$this->isInstanceOf(AuditSubject::class),
			$this->isInstanceOf(AuditedVersions::class),
			AuditLogger::STATUS_FAILURE,
			'Replacing with newname failed: cannot enable',
		);

		$outcome = (new AppReplacer($this->successors, $this->installer, $appManager, $this->audit))->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'newname');

		$this->assertSame(500, $outcome['statusCode']);
		$this->assertFalse($outcome['payload']['replaced']);
	}

	/**
	 * A successful replace leaves exactly two audit rows, in order: the
	 * successor's `install` row, then the old app's `replace` row. The install
	 * row is written inside the install path (recordInstallAudit, pinned by
	 * InstallerAuditHookTest), which is not unit-reachable end to end; the
	 * installer double writes it into the same log the same way.
	 */
	public function testASuccessfulReplaceWritesTwoAuditRows(): void {
		/** @var list<array{string, string, string}> $rows */
		$rows = [];
		$this->audit->method('record')->willReturnCallback(static function (AuditSubject $subject, AuditedVersions $versions, string $status) use (&$rows): void {
			$rows[] = [$subject->appId, $subject->operation, $status];
		});
		$this->successors->method('offerFor')->willReturn(self::OFFER);
		$this->installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request): array {
			$this->audit->record(
				new AuditSubject('admin', $request->target->appId, AuditLogger::OPERATION_INSTALL),
				new AuditedVersions(null, $request->target->version, $request->target->sourceOverride),
				AuditLogger::STATUS_SUCCESS,
			);

			return ['statusCode' => 200, 'payload' => []];
		});

		$outcome = $this->replacer()->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'newname');

		$this->assertTrue($outcome['payload']['replaced']);
		$this->assertSame([
			['newname', AuditLogger::OPERATION_INSTALL, AuditLogger::STATUS_SUCCESS],
			['oldname', AppReplacer::OPERATION_REPLACE, AuditLogger::STATUS_SUCCESS],
		], $rows);
	}

	public function testAFailedSuccessorInstallWritesNoReplaceRow(): void {
		/** @var list<string> $operations */
		$operations = [];
		$this->audit->method('record')->willReturnCallback(static function (AuditSubject $subject) use (&$operations): void {
			$operations[] = $subject->operation;
		});
		$this->successors->method('offerFor')->willReturn(self::OFFER);
		$this->installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request): array {
			$this->audit->record(
				new AuditSubject('admin', $request->target->appId, AuditLogger::OPERATION_INSTALL),
				new AuditedVersions(null, $request->target->version, $request->target->sourceOverride),
				AuditLogger::STATUS_FAILURE,
				'SHA-256 mismatch',
			);

			return ['statusCode' => 409, 'payload' => ['message' => 'SHA-256 mismatch']];
		});
		$this->appManager->expects($this->never())->method('disableApp');

		$outcome = $this->replacer()->replace(new AuditSubject('admin', 'oldname', AppReplacer::OPERATION_REPLACE), 'newname');

		$this->assertFalse($outcome['payload']['replaced']);
		$this->assertSame([AuditLogger::OPERATION_INSTALL], $operations);
	}
}
