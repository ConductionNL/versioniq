<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Instance;

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCA\Versioniq\Service\Instance\ManifestBuilder;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * The manifest of this instance (install-multi-instance-promotion design D3).
 *
 * @covers \OCA\Versioniq\Service\Instance\ManifestBuilder
 * @uses \OCA\Versioniq\Service\Instance\InstanceConnectionStore
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 * @uses \OCA\Versioniq\Db\AuditEntry
 * @spec openspec/changes/install-multi-instance-promotion/design.md
 */
final class ManifestBuilderTest extends TestCase {
	private const FORGE_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	/** @var array<string, string> */
	private array $config = [];

	/** @var array<string, ?AuditEntry> appId:version => newest successful audit row */
	private array $auditRows = [];

	/**
	 * @param list<array<string, mixed>> $cards
	 * @param array<string, ?SourceBinding> $bindings
	 */
	private function builder(array $cards, array $bindings = []): ManifestBuilder {
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getInstalledApps')->willReturn($cards);
		$installer->method('getBinding')->willReturnCallback(static fn (string $appId): ?SourceBinding => $bindings[$appId] ?? null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$store = new InstanceConnectionStore($appConfig, $this->createMock(ICrypto::class), $this->createMock(ITimeFactory::class));

		$audit = $this->createMock(AuditEntryMapper::class);
		$audit->method('findNewestSuccessTo')->willReturnCallback(fn (string $appId, string $version): ?AuditEntry => $this->auditRows[$appId . ':' . $version] ?? null);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->with('versioniq')->willReturn('1.9.0');
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->with('/')->willReturn('https://test.example.org/');
		$server = $this->createMock(ServerVersionProvider::class);
		$server->method('current')->willReturn('32.0.1');

		return new ManifestBuilder($installer, $store, $audit, $apps, $url, $server);
	}

	/** @return array<string, mixed> */
	private static function card(string $id, ?string $version, ?string $source = null, ?array $lkg = null): array {
		return [
			'id' => $id, 'label' => $id, 'description' => '', 'summary' => '', 'preview' => '', 'isCore' => false, 'isShipped' => false,
			'boundSourceId' => $source, 'manageable' => true, 'warning' => null, 'installedVersion' => $version, 'state' => 'enabled', 'lkg' => $lkg,
		];
	}

	public function testTheInstanceIsNamedAfterItsHostAndStageWhenUnset(): void {
		$manifest = $this->builder([])->build();

		self::assertSame(['instanceName' => 'test.example.org', 'stage' => '', 'serverVersion' => '32.0.1', 'versioniqVersion' => '1.9.0', 'apps' => []], $manifest);
	}

	public function testTheConfiguredNameAndStageAreUsed(): void {
		$this->config['instance.self'] = json_encode(['name' => 'Test', 'stage' => 'test', 'order' => 0]);

		$manifest = $this->builder([])->build();

		self::assertSame('Test', $manifest['instanceName']);
		self::assertSame('test', $manifest['stage']);
	}

	public function testAnAppStoreAppHasNoDigestAndRunsSinceItsLastKnownGoodRecord(): void {
		$lkg = ['version' => '2.0.0', 'recordedAt' => '2026-11-01T10:00:00+00:00', 'sourceId' => null];
		$manifest = $this->builder([self::card('calendar', '2.0.0', null, $lkg)], ['calendar' => new SourceBinding(SourceBinding::KIND_APPSTORE)])->build();

		self::assertSame([[
			'appId' => 'calendar', 'installedVersion' => '2.0.0', 'state' => 'enabled', 'sourceId' => null, 'sha256' => null, 'runningSince' => '2026-11-01T10:00:00+00:00',
		]], $manifest['apps']);
	}

	public function testAForgeAppCarriesItsRecordedDigestAndFallsBackToTheAuditRow(): void {
		$binding = new SourceBinding(SourceBinding::KIND_GITHUB_RELEASE, ['owner' => 'ConductionNL', 'repo' => 'decidiq', 'sha256' => ['1.4.0' => self::FORGE_SHA]]);
		$entry = new AuditEntry();
		$entry->setCreatedAt('2026-11-03 07:30:00');
		$this->auditRows['decidiq:1.4.0'] = $entry;
		$staleLkg = ['version' => '1.3.0', 'recordedAt' => '2026-10-01T10:00:00+00:00', 'sourceId' => 'github'];

		$app = $this->builder([self::card('decidiq', '1.4.0', 'github', $staleLkg)], ['decidiq' => $binding])->build()['apps'][0];

		self::assertSame(self::FORGE_SHA, $app['sha256']);
		self::assertSame('github', $app['sourceId']);
		self::assertSame('2026-11-03T07:30:00+00:00', $app['runningSince']);
	}

	public function testRunningSinceIsNullWhenNothingRecordsIt(): void {
		$apps = $this->builder([self::card('decidiq', '1.4.0'), self::card('pending', null)])->build()['apps'];

		self::assertNull($apps[0]['runningSince']);
		self::assertNull($apps[0]['sha256']);
		self::assertNull($apps[1]['installedVersion']);
		self::assertNull($apps[1]['runningSince']);
	}
}
