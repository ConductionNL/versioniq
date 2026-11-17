<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AppVersionChange;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\ChangeOrigin;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Audit\TriggerDescriber;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Audit\ExternalChangeRecorder
 * @uses \OCA\Versioniq\Service\Audit\AppVersionChange
 * @uses \OCA\Versioniq\Service\Audit\AuditSubject
 * @uses \OCA\Versioniq\Service\Audit\ChangeOrigin
 * @uses \OCA\Versioniq\Service\Audit\AuditedVersions
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class ExternalChangeRecorderTest extends TestCase {
	/** @var list<array{0: AuditSubject, 1: AuditedVersions, 2: string, 3: ?string}> */
	private array $rows = [];

	private function recorder(?SourceBinding $binding = null, ?SeenStateStore $seen = null): ExternalChangeRecorder {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->rows[] = [$subject, $versions, $status, $message];
		});
		$bindings = $this->createMock(SourceBindingStore::class);
		$bindings->method('get')->willReturn($binding);
		$triggers = $this->createMock(TriggerDescriber::class);
		$triggers->method('origin')->willReturn(new ChangeOrigin('occ app:update forms', 'system'));

		return new ExternalChangeRecorder($seen ?? $this->createMock(SeenStateStore::class), $audit, $bindings, $triggers);
	}

	public function testTheOriginIsWhatTheTriggerDescriberSays(): void {
		$origin = $this->recorder()->origin();

		$this->assertSame(['occ app:update forms', 'system'], [$origin->trigger, $origin->actor]);
	}

	public function testAnUpdateRowCarriesTheBoundSource(): void {
		$this->recorder(SourceBinding::github('conductionnl', 'forms'))->recordUpdate(new AppVersionChange('forms', '4.3.0', null), new ChangeOrigin('hourly', 'system'));

		[$subject, $versions, $status, $message] = $this->rows[0];
		$this->assertSame(['system', 'forms', 'external_update'], [$subject->actorUid, $subject->appId, $subject->operation]);
		$this->assertSame(['4.3.0', null, 'github:conductionnl/forms'], [$versions->fromVersion, $versions->toVersion, $versions->sourceId]);
		$this->assertSame(['success', 'hourly'], [$status, $message]);
	}

	public function testAnAppStoreAppHasNoSource(): void {
		$this->recorder(SourceBinding::appStore())->recordDisable(new AppVersionChange('forms', '4.3.0', '4.3.0'), new ChangeOrigin('POST /settings/apps/disable', 'bob'));

		[$subject, $versions, $status, $message] = $this->rows[0];
		$this->assertSame(['bob', 'external_disable'], [$subject->actorUid, $subject->operation]);
		$this->assertSame(['4.3.0', '4.3.0', null], [$versions->fromVersion, $versions->toVersion, $versions->sourceId]);
		$this->assertSame('POST /settings/apps/disable', $message);
	}

	public function testAReactivationRowCarriesItsOutcome(): void {
		$recorder = $this->recorder();
		$recorder->recordReactivate(new AppVersionChange('forms', null, '4.3.0'), null);
		$recorder->recordReactivate(new AppVersionChange('forms', null, '4.3.0'), 'dependency missing');

		$this->assertSame(['system', 'reactivate', 'success', 'Enabled again after an update left it disabled.'], [$this->rows[0][0]->actorUid, $this->rows[0][0]->operation, $this->rows[0][2], $this->rows[0][3]]);
		$this->assertSame(['failure', 'Could not enable the app after an update: dependency missing'], [$this->rows[1][2], $this->rows[1][3]]);
		$this->assertSame([null, '4.3.0'], [$this->rows[0][1]->fromVersion, $this->rows[0][1]->toVersion]);
	}

	public function testNothingIsRecordedBeforeTheBaseline(): void {
		$seen = $this->createMock(SeenStateStore::class);
		$seen->method('read')->willReturn(null);
		$seen->expects($this->never())->method('rememberVersion');

		$this->assertFalse($this->recorder(null, $seen)->versionChanged('forms', '4.4.0'));
		$this->assertSame([], $this->rows);
	}

	public function testAnAppNotSeenBeforeIsRecordedFromNothingAndRemembered(): void {
		$seen = $this->createMock(SeenStateStore::class);
		$seen->method('read')->willReturn(['serverVersion' => '33.0.2', 'apps' => [], 'disabledByUpdate' => []]);
		$seen->expects($this->once())->method('rememberVersion')->with('forms', '4.4.0');

		$this->assertTrue($this->recorder(null, $seen)->versionChanged('forms', '4.4.0'));
		$this->assertSame([null, '4.4.0'], [$this->rows[0][1]->fromVersion, $this->rows[0][1]->toVersion]);
		$this->assertSame('occ app:update forms', $this->rows[0][3]);
	}
}
