<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Snapshot\InstanceSnapshot;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Taking a snapshot (install-bulk-rollback design D1/D2): the apps installed
 * now, stored, and one `snapshot` audit row with the id and the reason.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\SnapshotTaker
 * @uses \OCA\Versioniq\Service\Snapshot\InstanceSnapshot
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
 */
final class SnapshotTakerTest extends TestCase {
	/** @var list<InstanceSnapshot> */
	private array $saved = [];

	/** @var list<array{0: AuditSubject, 1: AuditedVersions, 2: string, 3: ?string}> */
	private array $audited = [];

	private function taker(\Throwable|null $saveError = null): SnapshotTaker {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			['id' => 'openregister', 'installedVersion' => '2.3.0', 'state' => 'enabled', 'boundSourceId' => 'github:c/or'],
			['id' => 'opencatalogi', 'installedVersion' => '1.8.0', 'state' => 'disabled'],
			['id' => 'notyet', 'installedVersion' => null, 'state' => 'notInstalled'],
		]);
		$store = $this->createMock(SnapshotStore::class);
		$store->method('save')->willReturnCallback(function (InstanceSnapshot $snapshot) use ($saveError): void {
			if ($saveError !== null) {
				throw $saveError;
			}
			$this->saved[] = $snapshot;
		});
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $versions, $status, $message];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-09-26T01:00:00.123456', new \DateTimeZone('UTC')));

		return new SnapshotTaker($installer, $store, $audit, $time);
	}

	public function testItStoresTheInstalledAppsAndAuditsTheSnapshot(): void {
		$snapshot = $this->taker()->take('before_auto_update', 'system');

		$this->assertSame('20260926T010000-123456', $snapshot->id);
		$this->assertSame('2026-09-26T01:00:00+00:00', $snapshot->takenAt);
		$this->assertSame('before_auto_update', $snapshot->reason);
		$this->assertSame([
			'opencatalogi' => ['version' => '1.8.0', 'sourceId' => null, 'enabled' => false],
			'openregister' => ['version' => '2.3.0', 'sourceId' => 'github:c/or', 'enabled' => true],
		], $snapshot->apps);
		$this->assertSame([$snapshot], $this->saved);

		$this->assertCount(1, $this->audited);
		[$subject, $versions, $status, $message] = $this->audited[0];
		$this->assertSame('system', $subject->actorUid);
		$this->assertSame('versioniq', $subject->appId);
		$this->assertSame(AuditLogger::OPERATION_SNAPSHOT, $subject->operation);
		$this->assertNull($versions->fromVersion);
		$this->assertSame(AuditLogger::STATUS_SUCCESS, $status);
		$this->assertSame('Snapshot 20260926T010000-123456 (before_auto_update) of 2 apps', $message);
	}

	public function testANameIsTrimmedAndABlankOneIsDropped(): void {
		$this->assertSame('Before the October upgrade', $this->taker()->take('manual', 'alice', '  Before the October upgrade ')->name);
		$this->assertNull($this->taker()->take('manual', 'alice', '   ')->name);
		$this->assertSame('alice', $this->saved[0]->takenBy);
	}

	public function testAnEmptyActorIsRecordedAsSystem(): void {
		$this->assertSame('system', $this->taker()->take('manual', '')->takenBy);
	}

	public function testAnUnknownReasonIsRefusedBeforeAnythingIsStored(): void {
		try {
			$this->taker()->take('weekly', 'system');
			$this->fail('an unknown reason was accepted');
		} catch (\InvalidArgumentException $error) {
			$this->assertStringContainsString('weekly', $error->getMessage());
		}
		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->audited);
	}

	public function testAFullNamedCapIsNotAudited(): void {
		$this->expectException(\OverflowException::class);
		try {
			$this->taker(new \OverflowException('full'))->take('manual', 'alice', 'one more');
		} finally {
			$this->assertSame([], $this->audited);
		}
	}
}
