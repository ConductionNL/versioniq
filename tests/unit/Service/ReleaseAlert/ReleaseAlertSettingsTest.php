<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

require_once __DIR__ . '/ReleaseAlertTestCase.php';

use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;

/**
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 */
final class ReleaseAlertSettingsTest extends ReleaseAlertTestCase {
	public function testTheScopeDefaultsToForgeAndAnUnknownStoredValueReadsAsTheDefault(): void {
		self::assertSame('forge', $this->settings()->scope());

		$this->stored[ReleaseAlertSettings::KEY_SCOPE] = 'everything';
		self::assertSame('forge', $this->settings()->scope());
	}

	public function testAnUnknownScopeIsRefusedAndNothingStored(): void {
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->settings()->setScope('weekly', 'alice');
		} finally {
			self::assertArrayNotHasKey(ReleaseAlertSettings::KEY_SCOPE, $this->stored);
			self::assertSame([], $this->audited);
		}
	}

	public function testForgeLeavesAppStoreAndUnboundAppsToNextcloud(): void {
		$settings = $this->settings();

		self::assertTrue($settings->announces(self::event('hermiq', '1.5.0', 'github:ConductionNL/hermiq')));
		self::assertFalse($settings->announces(self::event('calendar', '5.2.0', 'appstore')));
		self::assertFalse($settings->announces(self::event('legacy', '0.9.0', null)));

		$settings->setScope('all', 'alice');
		self::assertTrue($settings->announces(self::event('calendar', '5.2.0', 'appstore')));

		$settings->setScope('off', 'alice');
		self::assertFalse($settings->announces(self::event('hermiq', '1.5.0')));
	}

	public function testEveryScopeChangeIsAuditedUnderTheAdmin(): void {
		$this->settings()->setScope('all', 'alice');

		self::assertCount(1, $this->audited);
		self::assertSame('alice', $this->audited[0][0]->actorUid);
		self::assertSame('settings', $this->audited[0][0]->operation);
		self::assertSame('success', $this->audited[0][1]);
		self::assertSame('Settings changed: release_alerts.scope=all', $this->audited[0][2]);
	}

	public function testLoggedReleasesAreRead(): void {
		$settings = $this->settings();
		$settings->logReleases([self::event('hermiq', '1.5.0')]);

		self::assertSame([self::event('hermiq', '1.5.0')], $settings->releases());
	}
}
