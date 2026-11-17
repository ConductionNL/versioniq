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
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceLookup;
use OCA\Versioniq\Service\Source\SourceRegistry;
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

	/**
	 * @param array<string, ?SourceBinding> $bindings
	 */
	private function withSources(array $bindings, ?bool $archived): LifecycleSignals {
		$store = $this->createMock(SourceBindingStore::class);
		$store->method('get')->willReturnCallback(static fn (string $appId): ?SourceBinding => $bindings[$appId] ?? null);
		$forge = $this->createMock(ForgeReleaseSource::class);
		$forge->method('isArchived')->willReturn($archived);
		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturnCallback(
			fn (SourceBinding $binding): SourceInterface => $binding->kind === SourceBinding::KIND_APPSTORE ? $this->createMock(AppStoreSource::class) : $forge
		);

		[$appConfig] = InMemoryAppConfig::for($this, []);

		return new LifecycleSignals($appConfig, $this->createMock(ITimeFactory::class), new SourceLookup($registry, $store));
	}

	public function testAnArchivedForgeRepositoryIsArchived(): void {
		$this->assertTrue($this->withSources(['oldapp' => SourceBinding::github('ConductionNL', 'oldapp')], true)->archived('oldapp'));
		$this->assertFalse($this->withSources(['deck' => SourceBinding::github('nextcloud', 'deck')], false)->archived('deck'));
	}

	public function testAStoreAppAnUnboundAppAndAnUnaskedForgeAreUnknown(): void {
		$signals = $this->withSources(['maps' => SourceBinding::appStore(), 'deck' => SourceBinding::github('nextcloud', 'deck')], null);

		$this->assertNull($signals->archived('maps'));
		$this->assertNull($signals->archived('unbound'));
		$this->assertNull($signals->archived('deck'));
	}

	public function testWithoutTheSourcesNothingIsAsked(): void {
		$this->assertNull($this->signals()->archived('deck'));
	}
}
