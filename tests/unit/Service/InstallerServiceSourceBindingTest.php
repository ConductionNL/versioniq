<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the source-management accessors of InstallerService: an
 * allowed binding is checked before it is stored, and reading, clearing and
 * the registry / allowlist getters delegate to the injected collaborators.
 *
 * @covers \OCA\Versioniq\Service\InstallerService
 * @uses \OCA\Versioniq\Service\Installer\InstallState
 * @uses \OCA\Versioniq\Service\Installer\ReleaseInstallers
 * @uses \OCA\Versioniq\Service\Installer\ServerAccess
 * @uses \OCA\Versioniq\Service\Source\SourceLookup
 * @uses \OCA\Versioniq\Service\Settings\ConfigStores
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class InstallerServiceSourceBindingTest extends TestCase {
	use BuildsByParameterName;

	public function testAnAllowedBindingIsCheckedBeforeItIsStored(): void {
		$binding = SourceBinding::github('conduction', 'mail');
		$order = [];

		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->once())->method('assertBindingAllowed')->with($this->identicalTo($binding))
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'assert';
			});
		$store = $this->createMock(SourceBindingStore::class);
		$store->expects($this->once())->method('set')->with('mail', $this->identicalTo($binding))
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'set';
			});

		$service = $this->buildByName(InstallerService::class, [
			'trustedSources' => $trusted,
			'bindingStore' => $store,
		]);
		$service->bindSource('mail', $binding);

		$this->assertSame(['assert', 'set'], $order);
	}

	public function testGetBindingReturnsWhatTheStoreHolds(): void {
		$binding = SourceBinding::github('conduction', 'deck');
		$store = $this->createMock(SourceBindingStore::class);
		$store->method('get')->willReturnMap([
			['deck', $binding],
			['unbound', null],
		]);

		$service = $this->buildByName(InstallerService::class, ['bindingStore' => $store]);

		$this->assertSame($binding, $service->getBinding('deck'));
		$this->assertNull($service->getBinding('unbound'));
	}

	public function testClearBindingClearsOnlyThatApp(): void {
		$store = $this->createMock(SourceBindingStore::class);
		$store->expects($this->once())->method('clear')->with('calendar');
		$store->expects($this->never())->method('set');

		$this->buildByName(InstallerService::class, ['bindingStore' => $store])->clearBinding('calendar');
	}

	public function testGettersExposeTheInjectedAllowlistAndRegistry(): void {
		$trusted = $this->createMock(TrustedSourceList::class);
		$registry = $this->createMock(SourceRegistry::class);

		$service = $this->buildByName(InstallerService::class, [
			'trustedSources' => $trusted,
			'sourceRegistry' => $registry,
		]);

		$this->assertSame($trusted, $service->getTrustedSources());
		$this->assertSame($registry, $service->getSourceRegistry());
	}
}
