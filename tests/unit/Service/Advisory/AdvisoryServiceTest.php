<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySourceInterface;
use OCA\Versioniq\Service\Advisory\BranchAwareRange;
use OCA\Versioniq\Service\Advisory\FixStatus;
use OCA\Versioniq\Service\Advisory\NextcloudAdvisoryFeed;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryServiceTest extends TestCase {
	use BuildsByParameterName;

	private function service(): AdvisoryService {
		return $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $this->createMock(SourceBindingStore::class),
			'appManager' => $this->createMock(IAppManager::class),
			'advisoryFeed' => $this->quietFeed(),
			'branchRange' => new BranchAwareRange(),
			'serverVersion' => $this->createMock(ServerVersionProvider::class),
			'logger' => $this->createMock(LoggerInterface::class),
		]);
	}

	/**
	 * A feed that answers with nothing. These tests exercise the pure
	 * evaluation path, so the central feed must not contribute advisories of
	 * its own — otherwise a change to the feed would move assertions about
	 * clause semantics.
	 */
	private function quietFeed(): NextcloudAdvisoryFeed {
		$feed = $this->createMock(NextcloudAdvisoryFeed::class);
		$feed->method('fetchAll')->willReturn(['advisories' => [], 'error' => null]);

		return $feed;
	}

	/**
	 * A service whose central feed answers with the given map, and whose
	 * enabled-app list and versions are fixed.
	 *
	 * @param array<string, list<array<string, mixed>>> $feedAdvisories
	 * @param array<string, string> $installedVersions app id => version
	 */
	private function serviceWithFeed(array $feedAdvisories, array $installedVersions, string $serverVersion = '31.0.2'): AdvisoryService {
		$feed = $this->createMock(NextcloudAdvisoryFeed::class);
		$feed->method('fetchAll')->willReturn(['advisories' => $feedAdvisories, 'error' => null]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(array_keys($installedVersions));
		$appManager->method('getAppVersion')->willReturnCallback(
			static fn (string $appId): string => $installedVersions[$appId] ?? '',
		);

		// A source with no advisory capability — the App Store case, which is
		// 87 of 88 apps on a real instance.
		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($this->createMock(SourceInterface::class));

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturn(SourceBinding::appStore());

		$provider = $this->createMock(ServerVersionProvider::class);
		$provider->method('current')->willReturn($serverVersion);

		return $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $registry,
			'bindingStore' => $bindingStore,
			'appManager' => $appManager,
			'advisoryFeed' => $feed,
			'branchRange' => new BranchAwareRange(),
			'serverVersion' => $provider,
			'logger' => $this->createMock(LoggerInterface::class),
		]);
	}

	/**
	 * A feed-shaped advisory: carries `patchedVersions`, which is what routes
	 * it through branch-aware evaluation.
	 *
	 * @param list<string> $patched
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}
	 */
	private function feedAdvisory(string $id, array $patched, string $range = ''): array {
		return [
			'id' => $id,
			'severity' => 'high',
			'summary' => 'Feed advisory ' . $id,
			'affected' => $range === '' ? [] : [$range],
			'firstPatchedVersion' => $patched[0] ?? null,
			'patchedVersions' => $patched,
		];
	}

	/**
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}> $advisories
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}
	 */
	private function advisory(string $id, array $affected, ?string $firstPatched): array {
		return [
			'id' => $id,
			'severity' => 'high',
			'summary' => 'Example advisory ' . $id,
			'affected' => $affected,
			'firstPatchedVersion' => $firstPatched,
		];
	}

	public function testNoAdvisoriesYieldsNoneState(): void {
		$result = $this->service()->evaluate('1.2.0', [], ['1.2.0', '1.3.0']);

		$this->assertSame(AdvisoryService::STATE_NONE, $result['state']);
		$this->assertSame([], $result['advisories']);
		$this->assertNull($result['recommendedVersion']);
	}

	public function testInstalledVersionUnaffectedYieldsAdvisoryAvailable(): void {
		// Advisory affects only < 1.1.0; installed 1.2.0 is safe.
		$advisories = [$this->advisory('GHSA-aaaa', ['< 1.1.0'], '1.1.0')];

		$result = $this->service()->evaluate('1.2.0', $advisories, ['1.2.0', '1.3.0']);

		$this->assertSame(AdvisoryService::STATE_AVAILABLE, $result['state']);
		$this->assertCount(1, $result['advisories']);
		$this->assertNull($result['recommendedVersion']);
	}

	public function testPinnedToVulnerableFlaggedWithRecommendation(): void {
		// Installed 1.0.5 is within ">= 1.0.0, < 1.2.3"; fixed in 1.2.3.
		$advisories = [$this->advisory('GHSA-bbbb', ['>= 1.0.0', '< 1.2.3'], '1.2.3')];

		$result = $this->service()->evaluate('1.0.5', $advisories, ['1.0.5', '1.1.0', '1.2.3', '1.3.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertSame('1.2.3', $result['recommendedVersion']);
		$this->assertSame('GHSA-bbbb', $result['advisories'][0]['id']);
		// Summary/severity are surfaced; internal range fields are dropped.
		$this->assertArrayNotHasKey('affected', $result['advisories'][0]);
	}

	public function testRecommendationSkipsAvailableVersionsStillInRange(): void {
		// Installed 1.0.0 affected by "< 1.2.3"; 1.1.0 is still vulnerable, so
		// the nearest resolving version must be 1.2.3, not 1.1.0.
		$advisories = [$this->advisory('GHSA-cccc', ['< 1.2.3'], null)];

		$result = $this->service()->evaluate('1.0.0', $advisories, ['1.1.0', '1.2.3', '2.0.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertSame('1.2.3', $result['recommendedVersion']);
	}

	public function testStuckOnVulnerableWhenNoResolvingVersionExists(): void {
		// Affected by "<= 3.0.0" and no newer version is offered by the source.
		$advisories = [$this->advisory('GHSA-dddd', ['<= 3.0.0'], null)];

		$result = $this->service()->evaluate('3.0.0', $advisories, ['2.9.0', '3.0.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertNull($result['recommendedVersion']);
	}

	public function testEmptyAffectedRangeMeansAllVersionsAffected(): void {
		$advisories = [$this->advisory('GHSA-eeee', [], '9.9.9')];

		$result = $this->service()->evaluate('1.0.0', $advisories, ['1.0.0']);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
	}

	public function testCorrelateWiresSourceAndInstalledVersionEndToEnd(): void {
		$binding = SourceBinding::appStore();

		$source = new class implements SourceInterface, AdvisorySourceInterface {
			public function getKind(): string {
				return SourceBinding::KIND_APPSTORE;
			}

			public function getInstallerKind(): string {
				return self::INSTALLER_SIGNED;
			}

			public function listVersions(string $appId, SourceBinding $binding): array {
				return ['versions' => [['version' => '1.2.3'], ['version' => '1.0.5']], 'error' => null];
			}

			public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array {
				return null;
			}

			public function listAdvisories(string $appId, SourceBinding $binding): array {
				return [
					'advisories' => [[
						'id' => 'GHSA-ffff',
						'severity' => 'critical',
						'summary' => 'RCE',
						'affected' => ['< 1.2.3'],
						'firstPatchedVersion' => '1.2.3',
					]],
					'error' => null,
				];
			}
		};

		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($source);

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturn($binding);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.5');

		$service = $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $registry,
			'bindingStore' => $bindingStore,
			'appManager' => $appManager,
			'advisoryFeed' => $this->quietFeed(),
			'branchRange' => new BranchAwareRange(),
			'serverVersion' => $this->createMock(ServerVersionProvider::class),
			'logger' => $this->createMock(LoggerInterface::class),
		]);
		$result = $service->correlate('someapp');

		$this->assertSame('someapp', $result['appId']);
		$this->assertSame('1.0.5', $result['installedVersion']);
		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $result['state']);
		$this->assertSame('1.2.3', $result['recommendedVersion']);
		$this->assertNull($result['error']);
		// The listed fix carries no incompatibility mark (advisories-per-app-detail D2).
		$this->assertSame(FixStatus::INSTALLABLE, $result['fixStatus']);
	}

	/**
	 * The source is looked up through the binding stored for the app, not
	 * through the App Store default.
	 */
	public function testCorrelateAsksTheRegistryForTheAppsOwnBinding(): void {
		$binding = SourceBinding::github('acme', 'tool');

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->expects($this->once())->method('get')->with('someapp')->willReturn($binding);

		$registry = $this->createMock(SourceRegistry::class);
		$registry->expects($this->once())
			->method('get')
			->with($this->identicalTo($binding))
			->willReturn($this->createMock(SourceInterface::class));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.5');

		$service = $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $registry,
			'bindingStore' => $bindingStore,
			'appManager' => $appManager,
			'advisoryFeed' => $this->quietFeed(),
			'branchRange' => new BranchAwareRange(),
			'logger' => $this->createMock(LoggerInterface::class),
		]);

		$this->assertSame(AdvisoryService::STATE_NONE, $service->correlate('someapp')['state']);
	}

	public function testCorrelateReturnsNoneWhenSourceCannotAnswerAdvisories(): void {
		$binding = SourceBinding::appStore();

		// A source that implements only SourceInterface (no advisory capability).
		$source = $this->createMock(SourceInterface::class);

		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($source);

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturn($binding);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		$service = $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $registry,
			'bindingStore' => $bindingStore,
			'appManager' => $appManager,
			'advisoryFeed' => $this->quietFeed(),
			'branchRange' => new BranchAwareRange(),
			'serverVersion' => $this->createMock(ServerVersionProvider::class),
			'logger' => $this->createMock(LoggerInterface::class),
		]);
		$result = $service->correlate('plainapp');

		$this->assertSame(AdvisoryService::STATE_NONE, $result['state']);
		$this->assertNull($result['error']);
	}

	// ── The central feed: what #166 exists to fix ────────────────────────

	/**
	 * THE HEADLINE CASE. An App Store app's own source publishes no advisory
	 * data at all, so before this the app was recorded as "no advisories" with
	 * no error. The centrally-published feed is the only thing that covers it.
	 */
	public function testAnAppStoreAppIsCorrelatedFromTheCentralFeed(): void {
		$service = $this->serviceWithFeed(
			['mail' => [$this->feedAdvisory('GHSA-aaaa', ['3.7.25', '5.5.16'])]],
			['mail' => '3.6.0'],
		);

		$results = $service->correlateAll(60.0);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $results['mail']['state']);
		$this->assertSame('3.7.25', $results['mail']['recommendedVersion']);
		$this->assertNull($results['mail']['error']);
	}

	/**
	 * The branch-aware rule reaching the real evaluation path. Under the old
	 * AND semantics these four lower bounds collapse to `>= 4.3.0` and 3.6.0
	 * is cleared — a false negative on two thirds of real advisories.
	 */
	public function testMultipleLowerBoundsDoNotClearAVulnerableInstance(): void {
		$service = $this->serviceWithFeed(
			['mail' => [$this->feedAdvisory('GHSA-bbbb', ['3.7.25', '5.5.16', '5.6.20', '5.7.13'], '>= 3.5.0, >= 3.7.0, >= 4.1.0, >= 4.3.0')]],
			['mail' => '3.6.0'],
		);

		$results = $service->correlateAll(60.0);

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $results['mail']['state']);
	}

	/**
	 * And the other direction: an instance already on its branch's patch must
	 * not be dragged forward by a later branch's patch.
	 */
	public function testAnInstanceOnItsBranchPatchIsNotReportedVulnerable(): void {
		$service = $this->serviceWithFeed(
			['spreed' => [$this->feedAdvisory('GHSA-cccc', ['21.1.10', '22.0.11', '23.0.3'])]],
			['spreed' => '22.0.11'],
		);

		$results = $service->correlateAll(60.0);

		$this->assertSame(AdvisoryService::STATE_AVAILABLE, $results['spreed']['state'], 'patched, but the app has a security history');
		$this->assertNull($results['spreed']['recommendedVersion']);
	}

	/**
	 * The server is not an app, but it is the largest single subject in the
	 * feed — 95 of 277 advisories — so it gets its own row.
	 */
	public function testTheServerGetsItsOwnCorrelatedRow(): void {
		$service = $this->serviceWithFeed(
			[AdvisoryService::SERVER_KEY => [$this->feedAdvisory('GHSA-dddd', ['31.0.12'])]],
			['mail' => '5.7.13'],
			'31.0.5',
		);

		$results = $service->correlateAll(60.0);

		$this->assertArrayHasKey(AdvisoryService::SERVER_KEY, $results);
		$this->assertSame('31.0.5', $results[AdvisoryService::SERVER_KEY]['installedVersion']);
		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $results[AdvisoryService::SERVER_KEY]['state']);
		$this->assertSame('31.0.12', $results[AdvisoryService::SERVER_KEY]['recommendedVersion']);
		$this->assertSame(FixStatus::INSTALLABLE, $results[AdvisoryService::SERVER_KEY]['fixStatus']);
	}

	/**
	 * No server advisories means no server row — an empty row would read as
	 * "the server was checked and is fine" on an instance where the feed was
	 * never reached.
	 */
	public function testNoServerRowWhenTheFeedCarriesNoServerAdvisories(): void {
		$service = $this->serviceWithFeed(['mail' => []], ['mail' => '1.0.0']);

		$this->assertArrayNotHasKey(AdvisoryService::SERVER_KEY, $service->correlateAll(60.0));
	}

	/**
	 * The recommended version comes from the ADVISORY's patch list, not from
	 * whatever versions the source happens to offer. The publisher's stated
	 * fix is authoritative; an inferred one is a guess.
	 */
	public function testTheRecommendationComesFromTheAdvisoryNotTheVersionList(): void {
		$service = $this->serviceWithFeed(
			['tables' => [$this->feedAdvisory('GHSA-eeee', ['0.9.5'])]],
			['tables' => '0.9.0'],
		);

		$results = $service->correlateAll(60.0);

		$this->assertSame('0.9.5', $results['tables']['recommendedVersion']);
	}

	/**
	 * A feed read only in part is logged; with no budget left no app's source
	 * is asked and each app says so; an app whose correlation throws is
	 * reported with the error instead of aborting the sweep.
	 */
	public function testAPartialFeedTheBudgetAndAFailingApp(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context];
		});
		$feed = $this->createMock(NextcloudAdvisoryFeed::class);
		$feed->method('fetchAll')->willReturn(['advisories' => [], 'error' => 'page 2 failed']);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['mail', 'deck']);
		$appManager->method('getAppVersion')->willReturn('1.0.0');
		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willThrowException(new \RuntimeException('store down'));
		$service = $this->buildByName(AdvisoryService::class, [
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $bindingStore,
			'appManager' => $appManager,
			'advisoryFeed' => $feed,
			'branchRange' => new BranchAwareRange(),
			'serverVersion' => $this->createMock(ServerVersionProvider::class),
			'logger' => $logger,
		]);

		$outOfTime = $service->correlateAll(-1.0);
		$failing = $service->correlateAll(60.0);

		$this->assertSame(['mail', 'deck'], array_keys($outOfTime));
		$this->assertSame('Advisory correlation budget exceeded before this app was reached; its sources were not queried.', $outOfTime['deck']['error']);
		$this->assertSame('1.0.0', $outOfTime['deck']['installedVersion']);
		$this->assertSame('store down', $failing['mail']['error']);
		$this->assertSame([
			['AdvisoryService: the Nextcloud advisory feed could not be read in full', ['message' => 'page 2 failed']],
			['AdvisoryService: the Nextcloud advisory feed could not be read in full', ['message' => 'page 2 failed']],
			['AdvisoryService: correlation failed for app', ['app' => 'mail', 'message' => 'store down']],
			['AdvisoryService: correlation failed for app', ['app' => 'deck', 'message' => 'store down']],
		], $logged);
	}

	/**
	 * advisories-per-app-detail 1.2: every row carries the full history,
	 * newest first (undated last, then by id), each marked as affecting the
	 * installed version or not, while `advisories` keeps its meaning.
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#1.2
	 */
	public function testHistoryIsNewestFirstAndMarksWhatAffectsTheInstalledVersion(): void {
		$dated = static fn (array $advisory, ?string $publishedAt, array $cves = []): array => $advisory + ['publishedAt' => $publishedAt, 'cveIds' => $cves, 'url' => null];
		$advisories = [
			$dated($this->advisory('GHSA-old', ['< 1.0.0'], '1.0.0'), '2025-11-02T00:00:00Z'),
			$dated($this->advisory('GHSA-zzz', ['< 1.0.0'], '1.0.0'), null),
			$dated($this->advisory('GHSA-new', ['< 2.0.0'], '2.0.0'), '2026-08-01T00:00:00Z', ['CVE-2026-1111']),
			$dated($this->advisory('GHSA-aaa', ['< 1.0.0'], '1.0.0'), null),
		];

		$affected = $this->service()->evaluate('1.5.0', $advisories, ['2.0.0']);

		$this->assertSame(['GHSA-new', 'GHSA-old', 'GHSA-aaa', 'GHSA-zzz'], array_column($affected['history'], 'id'));
		$this->assertSame([true, false, false, false], array_column($affected['history'], 'affectsInstalled'));
		$this->assertSame(['CVE-2026-1111'], $affected['history'][0]['cveIds']);
		$this->assertSame('2026-08-01T00:00:00Z', $affected['history'][0]['publishedAt']);
		// `advisories` still holds only the active ones for an affected version.
		$this->assertSame(['GHSA-new'], array_column($affected['advisories'], 'id'));

		$safe = $this->service()->evaluate('2.0.0', $advisories, []);
		$this->assertSame(AdvisoryService::STATE_AVAILABLE, $safe['state']);
		$this->assertSame([false, false, false, false], array_column($safe['history'], 'affectsInstalled'));
		// ... and every advisory for an unaffected one, as before.
		$this->assertSame(['GHSA-old', 'GHSA-zzz', 'GHSA-new', 'GHSA-aaa'], array_column($safe['advisories'], 'id'));
	}

	/**
	 * advisories-per-app-detail 2.1: a version list that cannot be read for
	 * the fix status costs the row nothing but the explanation.
	 *
	 * @spec openspec/changes/advisories-per-app-detail/tasks.md#2.1
	 */
	public function testAFeedAppWhoseSourceCannotListVersionsKeepsItsState(): void {
		$service = $this->serviceWithFeed(
			['mail' => [$this->feedAdvisory('GHSA-aaaa', ['3.7.25', '5.5.16'])]],
			['mail' => '3.6.0'],
		);

		$mail = $service->correlateAll(60.0)['mail'];

		$this->assertSame(AdvisoryService::STATE_VULNERABLE, $mail['state']);
		$this->assertSame(FixStatus::NOT_IN_SOURCE, $mail['fixStatus']);
	}
}
