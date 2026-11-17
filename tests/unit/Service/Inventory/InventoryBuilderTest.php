<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
 */

namespace OCA\Versioniq\Tests\Unit\Service\Inventory;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Inventory\InventoryBuilder;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Inventory\InventoryBuilder
 * @uses   \OCA\Versioniq\Service\Source\SourceBinding
 */
class InventoryBuilderTest extends TestCase {
	private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/**
	 * An Apps-tab card.
	 */
	private static function card(string $id, string $state, ?string $version, bool $shipped = false): array {
		return [
			'id' => $id, 'label' => ucfirst($id), 'description' => '', 'summary' => '', 'preview' => '',
			'isCore' => false, 'isShipped' => $shipped, 'boundSourceId' => null, 'manageable' => true,
			'warning' => null, 'installedVersion' => $version, 'state' => $state, 'lkg' => null,
		];
	}

	private function builder(): InventoryBuilder {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn([
			self::card('files', 'enabled', '32.0.1', true),
			self::card('mail', 'disabled', '5.1.0'),
			self::card('openregister', 'enabled', '0.2.10'),
			self::card('deck', 'notInstalled', null),
		]);

		$bindings = $this->createMock(SourceBindingStore::class);
		$bindings->method('get')->willReturnCallback(
			static fn (string $appId): ?SourceBinding => $appId === 'openregister'
				? SourceBinding::github('ConductionNL', 'OpenRegister')->withRecordedSha('0.2.10', self::SHA)
				: null
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppInfo')->willReturnCallback(
			static fn (string $appId): ?array => match ($appId) {
				'files' => ['licence' => 'agpl'],
				'mail' => ['licence' => ['AGPL-3.0-or-later', 'MIT']],
				default => [],
			}
		);

		$server = $this->createMock(ServerVersionProvider::class);
		$server->method('current')->willReturn('32.0.1');

		return new InventoryBuilder($installer, $bindings, $appManager, $server);
	}

	public function testTheServerAndEveryInstalledAppAreListed(): void {
		$inventory = $this->builder()->build();

		$this->assertSame(['name' => 'nextcloud-server', 'version' => '32.0.1', 'purl' => 'pkg:generic/nextcloud-server@32.0.1'], $inventory['server']);
		$this->assertSame(['files', 'mail', 'openregister'], array_column($inventory['apps'], 'id'));
	}

	public function testAShippedAppStoreApp(): void {
		$files = $this->builder()->build()['apps'][0];

		$this->assertSame(
			['id' => 'files', 'name' => 'Files', 'version' => '32.0.1', 'licence' => 'agpl', 'shipped' => true,
				'source' => 'appstore', 'sha256' => null, 'purl' => 'pkg:generic/nextcloud-app/files@32.0.1'],
			$files
		);
	}

	public function testADisabledAppKeepsItsFirstLicence(): void {
		$mail = $this->builder()->build()['apps'][1];

		$this->assertSame('AGPL-3.0-or-later', $mail['licence']);
		$this->assertFalse($mail['shipped']);
	}

	public function testAGithubBoundAppCarriesItsRepoShaAndGithubPurl(): void {
		$openregister = $this->builder()->build()['apps'][2];

		$this->assertSame('github:ConductionNL/OpenRegister', $openregister['source']);
		$this->assertSame(self::SHA, $openregister['sha256']);
		$this->assertSame('pkg:github/conductionnl/openregister@0.2.10', $openregister['purl']);
		$this->assertNull($openregister['licence']);
	}
}
