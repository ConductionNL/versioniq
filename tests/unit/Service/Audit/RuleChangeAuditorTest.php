<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Audit\RuleChangeAuditor;
use PHPUnit\Framework\TestCase;

/**
 * The audit rows for changes to the update rules (audit-attribution-and-forwarding
 * task 2.1): the operation, the actor, the app and `field: before -> after` for
 * each changed field.
 *
 * @covers \OCA\Versioniq\Service\Audit\RuleChangeAuditor
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
 */
final class RuleChangeAuditorTest extends TestCase {
	/** @var list<array{subject: AuditSubject, versions: AuditedVersions, status: string, message: ?string}> */
	private array $rows = [];

	private function auditor(): RuleChangeAuditor {
		$logger = $this->createMock(AuditLogger::class);
		$logger->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->rows[] = ['subject' => $subject, 'versions' => $versions, 'status' => $status, 'message' => $message];
		});

		return new RuleChangeAuditor($logger);
	}

	public function testSettingAPolicyRecordsTheLevelBeforeAndAfter(): void {
		$this->auditor()->policySet('alice', 'openregister', 'patch', 'minor');

		self::assertCount(1, $this->rows);
		self::assertSame('alice', $this->rows[0]['subject']->actorUid);
		self::assertSame('openregister', $this->rows[0]['subject']->appId);
		self::assertSame('policy_set', $this->rows[0]['subject']->operation);
		self::assertSame(AuditLogger::STATUS_SUCCESS, $this->rows[0]['status']);
		self::assertSame('level: patch -> minor', $this->rows[0]['message']);
		self::assertNull($this->rows[0]['versions']->fromVersion);
		self::assertNull($this->rows[0]['versions']->toVersion);
	}

	public function testAFirstPolicyReadsNoneBefore(): void {
		$this->auditor()->policySet('alice', 'mail', null, 'all');

		self::assertSame('level: none -> all', $this->rows[0]['message']);
	}

	public function testClearingAPolicyRecordsTheLevelItHad(): void {
		$this->auditor()->policyCleared('bob', 'mail', 'minor');

		self::assertSame('policy_clear', $this->rows[0]['subject']->operation);
		self::assertSame('bob', $this->rows[0]['subject']->actorUid);
		self::assertSame('level: minor -> none', $this->rows[0]['message']);
	}

	public function testTurningAutomaticUpdatesOnRecordsTheSwitchAndBothWindows(): void {
		$this->auditor()->autoUpdateSettings(
			'alice',
			['enabled' => false, 'window' => '01:00-05:00'],
			['enabled' => true, 'window' => '02:00-04:00'],
		);

		self::assertCount(1, $this->rows);
		self::assertSame('auto_update_settings', $this->rows[0]['subject']->operation);
		self::assertSame('versioniq', $this->rows[0]['subject']->appId);
		self::assertSame('enabled: false -> true, window: 01:00-05:00 -> 02:00-04:00', $this->rows[0]['message']);
	}

	public function testOnlyTheChangedFieldIsNamed(): void {
		$this->auditor()->advisorySettings(
			'alice',
			['intervalHours' => 6, 'digestEnabled' => true],
			['intervalHours' => 12, 'digestEnabled' => true],
		);

		self::assertSame('advisory_settings', $this->rows[0]['subject']->operation);
		self::assertSame('intervalHours: 6 -> 12', $this->rows[0]['message']);
	}

	public function testNothingIsRecordedWhenNothingChanged(): void {
		$auditor = $this->auditor();
		$auditor->autoUpdateSettings('alice', ['enabled' => true, 'window' => ''], ['enabled' => true, 'window' => '']);
		$auditor->advisorySettings('alice', ['intervalHours' => 6, 'digestEnabled' => false], ['intervalHours' => 6, 'digestEnabled' => false]);

		self::assertSame([], $this->rows);
	}

	public function testAnEmptyWindowReadsNone(): void {
		$this->auditor()->autoUpdateSettings('alice', ['enabled' => true, 'window' => ''], ['enabled' => true, 'window' => '02:00-04:00']);

		self::assertSame('window: none -> 02:00-04:00', $this->rows[0]['message']);
	}
}
