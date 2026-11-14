<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Mcp;

use OCA\Versioniq\Mcp\VersioniqToolProvider;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\InstallerService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Versioniq's assistant tools: five read-only descriptors, admin only, each
 * answering from the stored snapshots and services (admin-mcp-assistant).
 *
 * @covers \OCA\Versioniq\Mcp\VersioniqToolProvider
 *
 * @spec openspec/changes/admin-mcp-assistant/specs/assistant-tools/spec.md
 */
final class VersioniqToolProviderTest extends TestCase {

	private InstallerService&MockObject $installer;
	private AdvisoryResultStore&MockObject $advisories;
	private AvailabilityResultStore&MockObject $availability;

	/**
	 * A provider for a caller who is or is not an admin, or for no caller.
	 *
	 * @param bool|null $admin True for an admin, false for a user, null for no session.
	 *
	 * @return VersioniqToolProvider
	 */
	private function provider(?bool $admin = true): VersioniqToolProvider {
		$this->installer = $this->createMock(InstallerService::class);
		$this->advisories = $this->createMock(AdvisoryResultStore::class);
		$this->availability = $this->createMock(AvailabilityResultStore::class);

		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);
		if ($admin === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($user);
			$groups->method('isAdmin')->with('alice')->willReturn($admin);
		}

		return new VersioniqToolProvider($this->installer, $this->advisories, $this->availability, $session, $groups);
	}

	/**
	 * A stored advisory snapshot.
	 *
	 * @return array{advisories: array<string, mixed>, checkedAt: int}
	 */
	private static function snapshot(): array {
		return [
			'advisories' => [
				'deck' => [
					'appId' => 'deck',
					'installedVersion' => '1.2.0',
					'state' => 'pinned-to-vulnerable',
					'advisories' => [
						['id' => 'GHSA-1', 'severity' => 'high', 'summary' => 'XSS in cards'],
						['id' => 'GHSA-2', 'severity' => 'low', 'summary' => 'Timing leak'],
					],
					'recommendedVersion' => '1.2.3',
					'error' => null,
				],
				'mail' => [
					'appId' => 'mail',
					'installedVersion' => '3.0.0',
					'state' => 'pinned-to-vulnerable',
					'advisories' => [['id' => 'GHSA-3', 'severity' => 'critical', 'summary' => 'RCE']],
					'recommendedVersion' => null,
					'error' => null,
				],
				'notes' => ['appId' => 'notes', 'installedVersion' => '4.0.0', 'state' => 'advisory-available', 'advisories' => [['id' => 'GHSA-4', 'severity' => 'high', 'summary' => 'Old']], 'recommendedVersion' => null, 'error' => null],
			],
			'checkedAt' => 1763100000,
		];
	}

	/**
	 * Five tools, every id starting with versioniq., every one read-only.
	 */
	public function testAnAdminIsOfferedFiveReadOnlyTools(): void {
		$tools = $this->provider()->getTools();

		$this->assertSame('versioniq', $this->provider()->getAppId());
		$this->assertSame(
			['versioniq.listApps', 'versioniq.listAdvisories', 'versioniq.explainAdvisory', 'versioniq.listPendingUpdates', 'versioniq.listVersions'],
			array_column($tools, 'id')
		);
		foreach ($tools as $tool) {
			$this->assertStringStartsWith('versioniq.', $tool['id']);
			$this->assertSame(['id', 'name', 'description', 'inputSchema', 'readOnlyHint', 'destructiveHint', 'idempotentHint'], array_keys($tool));
			$this->assertTrue($tool['readOnlyHint'], $tool['id']);
			$this->assertFalse($tool['destructiveHint'], $tool['id']);
			$this->assertTrue($tool['idempotentHint'], $tool['id']);
			$this->assertSame('object', $tool['inputSchema']['type']);
		}
		$this->assertSame(['appId'], $tools[2]['inputSchema']['required']);
		$this->assertSame(['appId'], $tools[4]['inputSchema']['required']);
	}

	/**
	 * A user who is not an admin, or no session, is offered nothing and every
	 * call is refused before any service is read.
	 */
	public function testANonAdminIsOfferedNothingAndRefusedBeforeAnyRead(): void {
		foreach ([false, null] as $admin) {
			$provider = $this->provider(admin: $admin);
			$this->installer->expects($this->never())->method($this->anything());
			$this->advisories->expects($this->never())->method($this->anything());
			$this->availability->expects($this->never())->method($this->anything());

			$this->assertSame([], $provider->getTools());
			foreach (['versioniq.listApps', 'versioniq.listAdvisories', 'versioniq.explainAdvisory', 'versioniq.listPendingUpdates', 'versioniq.listVersions'] as $toolId) {
				$this->assertSame(['error' => 'Versioniq is available to administrators only.'], $provider->invokeTool($toolId, ['appId' => 'deck']));
			}
		}
	}

	/**
	 * listApps trims each managed app to id, name, version, state and source.
	 */
	public function testListAppsTrimsEachApp(): void {
		$provider = $this->provider();
		$this->installer->expects($this->once())->method('getInstalledApps')->willReturn([
			['id' => 'deck', 'label' => 'Deck', 'description' => 'Boards', 'installedVersion' => '1.2.0', 'state' => 'enabled', 'boundSourceId' => 'appstore', 'isCore' => false],
			['id' => 'mail', 'label' => 'Mail', 'installedVersion' => null, 'state' => 'notInstalled', 'boundSourceId' => null],
		]);

		$this->assertSame(['apps' => [
			['id' => 'deck', 'name' => 'Deck', 'installedVersion' => '1.2.0', 'state' => 'enabled', 'source' => 'appstore'],
			['id' => 'mail', 'name' => 'Mail', 'installedVersion' => null, 'state' => 'notInstalled', 'source' => null],
		]], $provider->invokeTool('versioniq.listApps', []));
	}

	/**
	 * listAdvisories returns the snapshot, or only one severity, leaving out apps without it.
	 */
	public function testListAdvisoriesFiltersBySeverity(): void {
		$provider = $this->provider();
		$this->advisories->method('read')->willReturn(self::snapshot());

		$this->assertSame(self::snapshot(), $provider->invokeTool('versioniq.listAdvisories', []));

		$high = $provider->invokeTool('versioniq.listAdvisories', ['severity' => 'HIGH']);
		$this->assertSame(['deck', 'notes'], array_keys($high['advisories']));
		$this->assertSame(['GHSA-1'], array_column($high['advisories']['deck']['advisories'], 'id'));
		$this->assertSame(1763100000, $high['checkedAt']);
	}

	/**
	 * explainAdvisory gives the advisories and the version to move to.
	 */
	public function testExplainAdvisoryGivesTheRecommendedVersion(): void {
		$provider = $this->provider();
		$this->advisories->method('read')->willReturn(self::snapshot());

		$this->assertSame([
			'appId' => 'deck',
			'checkedAt' => 1763100000,
			'installedVersion' => '1.2.0',
			'state' => 'pinned-to-vulnerable',
			'advisories' => self::snapshot()['advisories']['deck']['advisories'],
			'recommendedVersion' => '1.2.3',
			'reason' => null,
		], $provider->invokeTool('versioniq.explainAdvisory', ['appId' => ' deck ']));
	}

	/**
	 * explainAdvisory says why there is no version to move to.
	 */
	public function testExplainAdvisoryWithoutARecommendedVersionSaysWhy(): void {
		$provider = $this->provider();
		$this->advisories->method('read')->willReturn(self::snapshot());

		$mail = $provider->invokeTool('versioniq.explainAdvisory', ['appId' => 'mail']);
		$this->assertNull($mail['recommendedVersion']);
		$this->assertSame('No available version resolves these advisories yet.', $mail['reason']);
		$this->assertSame(['GHSA-3'], array_column($mail['advisories'], 'id'));

		$notes = $provider->invokeTool('versioniq.explainAdvisory', ['appId' => 'notes']);
		$this->assertSame('The installed version is not affected by the published advisories, so there is nothing to move to.', $notes['reason']);

		$unknown = $provider->invokeTool('versioniq.explainAdvisory', ['appId' => 'calendar']);
		$this->assertSame([], $unknown['advisories']);
		$this->assertStringStartsWith('There is no stored advisory result for this app', (string)$unknown['reason']);

		$this->assertSame(['error' => 'appId is required.'], $provider->invokeTool('versioniq.explainAdvisory', []));
	}

	/**
	 * listPendingUpdates is the stored availability snapshot as it is.
	 */
	public function testListPendingUpdatesReadsTheSnapshot(): void {
		$provider = $this->provider();
		$snapshot = ['updates' => ['deck' => ['latest' => '1.3.0']], 'checkedAt' => 1763000000];
		$this->availability->expects($this->once())->method('read')->willReturn($snapshot);

		$this->assertSame($snapshot, $provider->invokeTool('versioniq.listPendingUpdates', []));
	}

	/**
	 * listVersions lists one app's versions with server compatibility, and passes on a listing error.
	 */
	public function testListVersionsTrimsTheListing(): void {
		$provider = $this->provider();
		$this->installer->expects($this->exactly(2))->method('getAppVersions')->willReturnCallback(
			static fn (string $appId): array => $appId === 'deck'
				? ['installedVersion' => '1.2.0', 'source' => 'appstore', 'versions' => [
					['version' => '1.3.0', 'changelog' => 'long text', 'serverCompatible' => true, 'releasedAt' => '2026-10-01', 'recordedSha' => null, 'cachedOffline' => false],
					['version' => '1.2.0', 'changelog' => null, 'recordedSha' => 'abc', 'cachedOffline' => true],
				]]
				: ['installedVersion' => null, 'source' => 'github', 'versions' => [], 'hasError' => true, 'error' => 'Source unreachable']
		);

		$this->assertSame([
			'appId' => 'deck',
			'installedVersion' => '1.2.0',
			'source' => 'appstore',
			'versions' => [
				['version' => '1.3.0', 'serverCompatible' => true, 'releasedAt' => '2026-10-01'],
				['version' => '1.2.0', 'serverCompatible' => null, 'releasedAt' => null],
			],
		], $provider->invokeTool('versioniq.listVersions', ['appId' => 'deck']));
		$this->assertSame('Source unreachable', $provider->invokeTool('versioniq.listVersions', ['appId' => 'gone'])['error']);
	}

	/**
	 * An unknown tool id is refused by name.
	 */
	public function testAnUnknownToolIsRefused(): void {
		$this->assertSame(['error' => 'Unknown tool: versioniq.install'], $this->provider()->invokeTool('versioniq.install', ['appId' => 'deck']));
	}
}
