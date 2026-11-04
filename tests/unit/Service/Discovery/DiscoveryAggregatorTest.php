<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\Service\Discovery\AppStoreDiscovery;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\Discovery\DiscoveryFilter;
use OCA\Versioniq\Service\Discovery\DiscoveryHit;
use OCA\Versioniq\Service\Discovery\DiscoveryListing;
use OCA\Versioniq\Service\Discovery\DiscoveryResult;
use OCA\Versioniq\Service\Discovery\DiscoverySource;
use OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery;
use OCA\Versioniq\Service\Discovery\GithubSearchDiscovery;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

final class DiscoveryAggregatorTest extends TestCase {
	private function makeHit(string $providerId, string $appId, string $name, bool $installable = true, array $binding = []): DiscoveryHit {
		return new DiscoveryHit(
			appId: $appId,
			listing: new DiscoveryListing(
				name: $name,
				summary: $name . ' summary',
				iconUrl: $providerId === AppStoreDiscovery::ID ? 'https://example/icon.png' : null,
				homepageUrl: null,
			),
			source: new DiscoverySource(
				providerId: $providerId,
				binding: $binding ?: ['kind' => SourceBinding::KIND_APPSTORE],
				installable: $installable,
				installableReason: $installable ? null : 'reason',
			),
		);
	}

	private function buildAggregator(
		?DiscoveryResult $appstore = null,
		?DiscoveryResult $githubPrivate = null,
		?DiscoveryResult $githubSearch = null,
		array $installedApps = [],
	): DiscoveryAggregator {
		$appStore = $this->createMock(AppStoreDiscovery::class);
		$appStore->method('getId')->willReturn(AppStoreDiscovery::ID);
		$appStore->method('getLabel')->willReturn('Nextcloud App Store');
		$appStore->method('isEnabled')->willReturn(true);
		$appStore->method('search')->willReturn($appstore ?? DiscoveryResult::empty());

		$gp = $this->createMock(GithubPrivateDiscovery::class);
		$gp->method('getId')->willReturn(GithubPrivateDiscovery::ID);
		$gp->method('getLabel')->willReturn('GitHub (private)');
		$gp->method('isEnabled')->willReturn($githubPrivate !== null);
		$gp->method('search')->willReturn($githubPrivate ?? DiscoveryResult::empty());

		$gs = $this->createMock(GithubSearchDiscovery::class);
		$gs->method('getId')->willReturn(GithubSearchDiscovery::ID);
		$gs->method('getLabel')->willReturn('GitHub (public search)');
		$gs->method('isEnabled')->willReturn($githubSearch !== null);
		$gs->method('search')->willReturn($githubSearch ?? DiscoveryResult::empty());

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(array_keys($installedApps));
		$appManager->method('getAppVersion')->willReturnCallback(
			static fn (string $appId): string => $installedApps[$appId] ?? ''
		);

		return new DiscoveryAggregator($appManager, $appStore, $gp, $gs);
	}

	public function testEmptyProvidersReturnEmptyResults(): void {
		$result = $this->buildAggregator()->search('register');

		$this->assertSame([], $result['results']);
		$this->assertCount(3, $result['providers']);
	}

	public function testAppStoreHitFlowsThrough(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([$this->makeHit(AppStoreDiscovery::ID, 'openregister', 'Open Register')])
		)->search('register');

		$this->assertCount(1, $result['results']);
		$this->assertSame('openregister', $result['results'][0]['appId']);
		$this->assertSame('Open Register', $result['results'][0]['name']);
		$this->assertCount(1, $result['results'][0]['sourceCandidates']);
	}

	public function testDeduplicatesByAppId(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([$this->makeHit(AppStoreDiscovery::ID, 'openregister', 'Open Register')]),
			githubPrivate: new DiscoveryResult([
				$this->makeHit(
					GithubPrivateDiscovery::ID,
					'openregister',
					'openregister',
					binding: ['kind' => SourceBinding::KIND_GITHUB_RELEASE, 'owner' => 'ConductionNL', 'repo' => 'openregister']
				),
			]),
		)->search('register');

		$this->assertCount(1, $result['results']);
		$this->assertCount(2, $result['results'][0]['sourceCandidates']);
	}

	public function testInstalledAppAnnotatedWithVersion(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([$this->makeHit(AppStoreDiscovery::ID, 'openregister', 'Open Register')]),
			installedApps: ['openregister' => '0.2.13']
		)->search('register');

		$this->assertSame('0.2.13', $result['results'][0]['installedVersion']);
	}

	public function testInstalledOnlyFiltersOutNotInstalled(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([
				$this->makeHit(AppStoreDiscovery::ID, 'openregister', 'Open Register'),
				$this->makeHit(AppStoreDiscovery::ID, 'opencatalogi', 'Open Catalogi'),
			]),
			installedApps: ['openregister' => '0.2.13']
		)->search('open', new DiscoveryFilter(null, true));

		$this->assertCount(1, $result['results']);
		$this->assertSame('openregister', $result['results'][0]['appId']);
	}

	public function testSourceFilterRestrictsToNamedProvider(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([$this->makeHit(AppStoreDiscovery::ID, 'openregister', 'Open Register')]),
			githubPrivate: new DiscoveryResult([$this->makeHit(GithubPrivateDiscovery::ID, 'private_app', 'private-app',
				binding: ['kind' => SourceBinding::KIND_GITHUB_RELEASE, 'owner' => 'me', 'repo' => 'private-app']
			)]),
		)->search('register', new DiscoveryFilter([AppStoreDiscovery::ID]));

		$this->assertCount(1, $result['results']);
		$this->assertSame('openregister', $result['results'][0]['appId']);
	}

	public function testInstalledAppsSortedFirst(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([
				$this->makeHit(AppStoreDiscovery::ID, 'apple', 'Apple'),
				$this->makeHit(AppStoreDiscovery::ID, 'banana', 'Banana'),
			]),
			installedApps: ['banana' => '1.0.0']
		)->search('a');

		$this->assertSame('banana', $result['results'][0]['appId'], 'installed should come first');
		$this->assertSame('apple', $result['results'][1]['appId']);
	}

	public function testProviderErrorPropagatesToResultEnvelope(): void {
		$result = $this->buildAggregator(
			appstore: DiscoveryResult::failed('App Store unreachable'),
		)->search('register');

		$this->assertCount(1, $result['errors']);
		$this->assertSame(AppStoreDiscovery::ID, $result['errors'][0]['providerId']);
		$this->assertSame('App Store unreachable', $result['errors'][0]['message']);
	}

	public function testAppStoreSummaryWinsOverGithubSummary(): void {
		$result = $this->buildAggregator(
			appstore: new DiscoveryResult([$this->makeHit(AppStoreDiscovery::ID, 'openregister', 'Open Register')]),
			githubPrivate: new DiscoveryResult([
				new DiscoveryHit(
					appId: 'openregister',
					listing: new DiscoveryListing(
						name: 'openregister',
						summary: 'github description that should NOT win',
						iconUrl: null,
						homepageUrl: null,
					),
					source: new DiscoverySource(
						providerId: GithubPrivateDiscovery::ID,
						binding: ['kind' => SourceBinding::KIND_GITHUB_RELEASE, 'owner' => 'ConductionNL', 'repo' => 'openregister'],
						installable: true,
						installableReason: null,
					),
				),
			]),
		)->search('register');

		$this->assertCount(1, $result['results']);
		$this->assertSame('Open Register summary', $result['results'][0]['summary']);
	}
}
