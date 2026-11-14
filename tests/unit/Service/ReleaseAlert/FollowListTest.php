<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

require_once __DIR__ . '/ReleaseAlertTestCase.php';

use InvalidArgumentException;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\ReleaseAlert\FollowList;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The follow list: stored entries, validation, the cap, audit, and the forge
 * scope announcing a followed App Store app.
 *
 * @covers \OCA\Versioniq\Service\ReleaseAlert\FollowList
 * @covers \OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-an-admin-follows-the-releases-of-an-app-that-is-not-installed
 */
final class FollowListTest extends ReleaseAlertTestCase {
	private function follows(): FollowList {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $status, $message];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));

		return new FollowList($this->appConfig(), $audit, $time);
	}

	public function testAFollowedAppIsStoredWithItsSourceAndWhoAddedIt(): void {
		$follows = $this->follows();
		$follows->follow(' forms ', ' appstore ', 'alice');
		$follows->follow('hermiq', '', 'bob');

		self::assertSame(
			[
				['appId' => 'forms', 'sourceId' => 'appstore', 'addedBy' => 'alice', 'addedAt' => '2026-11-14T10:00:00+00:00'],
				['appId' => 'hermiq', 'sourceId' => null, 'addedBy' => 'bob', 'addedAt' => '2026-11-14T10:00:00+00:00'],
			],
			$follows->all()
		);
		self::assertTrue($follows->isFollowed('forms'));
		self::assertFalse($follows->isFollowed('calendar'));
		self::assertCount(2, $this->audited);
		self::assertSame('Settings changed: Following releases of forms from appstore', $this->audited[0][2]);
	}

	public function testFollowingAgainReplacesTheSource(): void {
		$follows = $this->follows();
		$follows->follow('hermiq', 'appstore', 'alice');
		$follows->follow('hermiq', 'github:ConductionNL/hermiq', 'alice');

		self::assertSame([['appId' => 'hermiq', 'sourceId' => 'github:ConductionNL/hermiq', 'addedBy' => 'alice', 'addedAt' => '2026-11-14T10:00:00+00:00']], $follows->all());
	}

	public function testMalformedIdsAreRefusedAndNothingIsStored(): void {
		foreach ([['Forms', null], ['', null], ['forms', 'github:bad repo'], ['a/b', 'appstore']] as [$appId, $sourceId]) {
			try {
				$this->follows()->follow($appId, $sourceId, 'alice');
				self::fail('accepted ' . $appId);
			} catch (InvalidArgumentException) {
			}
		}

		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
	}

	public function testTheListIsCapped(): void {
		$follows = $this->follows();
		for ($n = 1; $n <= FollowList::MAX_ENTRIES; $n++) {
			$follows->follow('app' . $n, null, 'alice');
		}

		$this->expectException(InvalidArgumentException::class);
		$follows->follow('one_more', null, 'alice');
	}

	public function testUnfollowingRemovesOnlyThatApp(): void {
		$follows = $this->follows();
		$follows->follow('forms', null, 'alice');
		$follows->follow('deck', null, 'alice');

		self::assertTrue($follows->unfollow('forms', 'alice'));
		self::assertFalse($follows->unfollow('forms', 'alice'));
		self::assertSame(['deck'], array_column($follows->all(), 'appId'));
		self::assertCount(3, $this->audited);
	}

	public function testAMalformedStoredValueReadsAsEmptyOrSkipsTheBadEntry(): void {
		$this->stored[FollowList::KEY] = 'not json';
		self::assertSame([], $this->follows()->all());

		$this->stored[FollowList::KEY] = '[{"appId": "forms", "sourceId": 7}, {"sourceId": "appstore"}, "x"]';
		self::assertSame([['appId' => 'forms', 'sourceId' => null, 'addedBy' => '', 'addedAt' => '']], $this->follows()->all());
	}

	public function testTheForgeScopeAnnouncesAFollowedAppStoreApp(): void {
		$config = $this->appConfig();
		$follows = $this->follows();
		$follows->follow('forms', 'appstore', 'alice');
		$settings = new ReleaseAlertSettings($config, new ReleaseEventLog($config), $this->createMock(AuditLogger::class), $follows);

		self::assertTrue($settings->announces(self::event('forms', '4.0.0', 'appstore')));
		self::assertFalse($settings->announces(self::event('calendar', '5.2.0', 'appstore')));
		self::assertSame(['forms'], array_column($settings->follows(), 'appId'));
	}
}
