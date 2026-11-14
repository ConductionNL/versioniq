<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\Service\Discovery\AppStoreDiscovery;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\Discovery\DiscoveryHit;
use OCA\Versioniq\Service\Discovery\DiscoveryListing;
use OCA\Versioniq\Service\Discovery\DiscoveryResult;
use OCA\Versioniq\Service\Discovery\DiscoverySource;
use OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery;
use OCA\Versioniq\Service\Discovery\GithubSearchDiscovery;
use OCA\Versioniq\Service\Discovery\PublisherTrust;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Publisher, trust marks and similar-name hints on aggregated Discover results.
 *
 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
 */
final class DiscoveryPublisherTest extends TestCase {
	private static function hit(string $providerId, string $appId, string $name, ?string $publisher, bool $featured = false, array $binding = ['kind' => 'appstore']): DiscoveryHit {
		return new DiscoveryHit(
			appId: $appId,
			listing: new DiscoveryListing(name: $name, summary: '', iconUrl: null, homepageUrl: null, publisher: $publisher, featured: $featured),
			source: new DiscoverySource(providerId: $providerId, binding: $binding, installable: true, installableReason: null),
		);
	}

	private function aggregator(DiscoveryResult $store, DiscoveryResult $search, ?PublisherTrust $trust = null): DiscoveryAggregator {
		$appStore = $this->createMock(AppStoreDiscovery::class);
		$appStore->method('getId')->willReturn(AppStoreDiscovery::ID);
		$appStore->method('isEnabled')->willReturn(true);
		$appStore->method('search')->willReturn($store);

		$private = $this->createMock(GithubPrivateDiscovery::class);
		$private->method('getId')->willReturn(GithubPrivateDiscovery::ID);
		$private->method('isEnabled')->willReturn(false);

		$public = $this->createMock(GithubSearchDiscovery::class);
		$public->method('getId')->willReturn(GithubSearchDiscovery::ID);
		$public->method('isEnabled')->willReturn(true);
		$public->method('search')->willReturn($search);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn([]);

		return new DiscoveryAggregator($appManager, $appStore, $private, $public, $trust);
	}

	/**
	 * @param list<array<string, mixed>> $results
	 * @return array<string, array<string, mixed>>
	 */
	private static function byId(array $results): array {
		return array_column($results, null, 'appId');
	}

	public function testLookAlikeNamesFromTwoPublishersPointAtEachOther(): void {
		$results = self::byId($this->aggregator(
			new DiscoveryResult([
				self::hit(AppStoreDiscovery::ID, 'whiteboard', 'Whiteboard', 'Nextcloud GmbH', true),
				self::hit(AppStoreDiscovery::ID, 'white_board', 'white-board', 'Someone Else'),
			]),
			new DiscoveryResult([
				self::hit(GithubSearchDiscovery::ID, 'whiteboardapp', 'WhiteboardApp', 'someone-else', false, ['kind' => 'github-release', 'owner' => 'someone-else', 'repo' => 'WhiteboardApp']),
			]),
		)->search('whiteboard')['results']);

		$this->assertSame('Nextcloud GmbH', $results['whiteboard']['publisher']);
		$this->assertSame(['featured'], $results['whiteboard']['marks']);
		$this->assertSame(
			[
				['appId' => 'whiteboardapp', 'name' => 'WhiteboardApp', 'publisher' => 'someone-else'],
				['appId' => 'white_board', 'name' => 'white-board', 'publisher' => 'Someone Else'],
			],
			$results['whiteboard']['similarTo']
		);
		$this->assertSame(['whiteboard', 'whiteboardapp'], array_column($results['white_board']['similarTo'], 'appId'));
		$this->assertSame('someone-else', $results['whiteboardapp']['publisher']);
	}

	public function testTheSamePublisherIsNotASimilarNameHint(): void {
		$results = DiscoveryAggregator::withSimilarNames([
			['appId' => 'a', 'name' => 'Notes', 'publisher' => 'Nextcloud GmbH'],
			['appId' => 'b', 'name' => 'notes_app', 'publisher' => 'nextcloud gmbh'],
			['appId' => 'c', 'name' => 'Deck', 'publisher' => 'Other'],
		]);

		$this->assertSame([[], [], []], array_column($results, 'similarTo'));
	}

	public function testNormalisationDropsCaseSeparatorsAndATrailingApp(): void {
		$this->assertSame('whiteboard', DiscoveryAggregator::normalisedName('White Board_App'));
		$this->assertSame('whiteboard', DiscoveryAggregator::normalisedName('white-board'));
		$this->assertSame('app', DiscoveryAggregator::normalisedName('App'));
	}

	public function testTrustMarksComeFromEveryCandidateInDisplayOrder(): void {
		$trust = $this->createMock(PublisherTrust::class);
		$trust->expects($this->exactly(2))->method('marksFor')->willReturnCallback(
			static fn (string $appId, array $binding, bool $featured): array => ($binding['kind'] === 'github-release')
				? [PublisherTrust::MARK_VERIFIED_ORG, PublisherTrust::MARK_TRUSTED]
				: [PublisherTrust::MARK_SHIPPED, ...($featured ? [PublisherTrust::MARK_FEATURED] : [])]
		);

		$results = $this->aggregator(
			new DiscoveryResult([self::hit(AppStoreDiscovery::ID, 'deck', 'Deck', 'Nextcloud GmbH', true)]),
			new DiscoveryResult([self::hit(GithubSearchDiscovery::ID, 'deck', 'deck', 'nextcloud', false, ['kind' => 'github-release', 'owner' => 'nextcloud', 'repo' => 'deck'])]),
			$trust,
		)->search('deck')['results'];

		$this->assertCount(1, $results);
		$this->assertSame('Nextcloud GmbH', $results[0]['publisher']);
		$this->assertSame(['shipped', 'featured', 'verifiedOrg', 'trusted'], $results[0]['marks']);
	}
}
