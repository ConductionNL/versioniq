<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

require_once __DIR__ . '/InMemoryAppConfig.php';

use OCA\Versioniq\Service\Lifecycle\LifecycleSignals;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
#[CoversClass(LifecycleSignals::class)]
final class LifecycleSignalsTest extends TestCase {
	private function signals(array $config = []): LifecycleSignals {
		[$appConfig] = InMemoryAppConfig::for($this, $config);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-11-15T12:00:00Z'));

		return new LifecycleSignals($appConfig, $time);
	}

	public function testAStoreAppTheCatalogueNoLongerListsIsRemovedFromTheStore(): void {
		$signals = $this->signals()->signals(AppStoreSource::NOT_IN_STORE, []);

		$this->assertSame(['removedFromStore' => true, 'noReleaseFor12Months' => null, 'lastReleasedAt' => null], $signals);
	}

	public function testAShippedAppIsNotRemovedFromTheStore(): void {
		// InstallerService rewrites the store miss for a shipped app into this explanation.
		$shipped = 'This app ships with Nextcloud itself: its version follows the server release and it is not distributed via the App Store. Bind a forge source to manage it from a repository instead.';

		$this->assertFalse($this->signals()->signals($shipped, [])['removedFromStore']);
		$this->assertFalse($this->signals()->signals('The source did not answer.', [])['removedFromStore']);
	}

	public function testAnAppWhoseNewestReleaseIsOlderThanAYearIsStale(): void {
		$signals = $this->signals()->signals(null, [
			['version' => '1.0.0', 'releasedAt' => '2024-01-10T00:00:00Z'],
			['version' => '1.1.0', 'releasedAt' => '2025-11-01T00:00:00Z'],
			['version' => '0.9.0', 'releasedAt' => null],
		]);

		$this->assertSame(['removedFromStore' => false, 'noReleaseFor12Months' => true, 'lastReleasedAt' => '2025-11-01T00:00:00Z'], $signals);
	}

	public function testAnAppWithARecentReleaseIsNotStale(): void {
		$signals = $this->signals()->signals(null, [['version' => '2.0.0', 'releasedAt' => '2025-11-16T00:00:00Z']]);

		$this->assertFalse($signals['noReleaseFor12Months']);
	}

	public function testAnAppWithoutReleaseDatesIsUnknownNotMaintained(): void {
		$this->assertNull($this->signals()->signals(null, [['version' => '1.0.0']])['noReleaseFor12Months']);
	}

	public function testTheStaleMonthsComeFromTheAppConfig(): void {
		$versions = [['version' => '2.0.0', 'releasedAt' => '2026-06-01T00:00:00Z']];

		$this->assertTrue($this->signals([LifecycleSignals::KEY_STALE_MONTHS => 3])->signals(null, $versions)['noReleaseFor12Months']);
		$this->assertFalse($this->signals([LifecycleSignals::KEY_STALE_MONTHS => 0])->signals(null, $versions)['noReleaseFor12Months'], 'a nonsense value falls back to twelve months');
	}
}
