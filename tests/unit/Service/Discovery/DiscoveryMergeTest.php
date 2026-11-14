<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\Service\Discovery\AppStoreDiscovery;
use OCA\Versioniq\Service\Discovery\DiscoveryHit;
use OCA\Versioniq\Service\Discovery\DiscoveryListing;
use OCA\Versioniq\Service\Discovery\DiscoveryMerge;
use OCA\Versioniq\Service\Discovery\DiscoveryResult;
use OCA\Versioniq\Service\Discovery\DiscoverySource;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for DiscoveryMerge: hits from several providers are grouped per
 * app id with one source candidate per hit, App Store metadata wins over
 * other providers only when it is present, installed apps sort first and
 * then by name, and provider errors are collected in order.
 *
 * @covers \OCA\Versioniq\Service\Discovery\DiscoveryMerge
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryHit
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryListing
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryResult
 * @uses \OCA\Versioniq\Service\Discovery\DiscoverySource
 */
final class DiscoveryMergeTest extends TestCase {
	private function hit(
		string $appId,
		string $provider,
		string $name,
		string $summary = '',
		?string $icon = null,
		bool $installable = true,
		?string $reason = null,
	): DiscoveryHit {
		return new DiscoveryHit(
			$appId,
			new DiscoveryListing($name, $summary, $icon, 'https://example.org/' . $appId),
			new DiscoverySource($provider, ['kind' => $provider], $installable, $reason),
		);
	}

	public function testHitsForTheSameAppAreGroupedWithOneCandidateEach(): void {
		$merge = new DiscoveryMerge([]);
		$merge->add('github-private', new DiscoveryResult([
			$this->hit('mail', 'github-private', 'Mail (fork)', 'From GitHub', null, false, 'untrusted'),
		]));
		$merge->add(AppStoreDiscovery::ID, new DiscoveryResult([
			$this->hit('mail', AppStoreDiscovery::ID, 'Mail', 'Store summary', 'https://store/icon.svg'),
		]));

		$this->assertSame([[
			'appId' => 'mail',
			'name' => 'Mail (fork)',
			'summary' => 'Store summary',
			'iconUrl' => 'https://store/icon.svg',
			'homepageUrl' => 'https://example.org/mail',
			'publisher' => null,
			'marks' => [],
			'similarTo' => [],
			'installedVersion' => null,
			'sourceCandidates' => [
				[
					'providerId' => 'github-private',
					'sourceBinding' => ['kind' => 'github-private'],
					'installable' => false,
					'installableReason' => 'untrusted',
				],
				[
					'providerId' => AppStoreDiscovery::ID,
					'sourceBinding' => ['kind' => AppStoreDiscovery::ID],
					'installable' => true,
					'installableReason' => null,
				],
			],
		]], $merge->results(false));
	}

	public function testEmptyAppStoreMetadataDoesNotOverwriteAnotherProvider(): void {
		$merge = new DiscoveryMerge([]);
		$merge->add('github-search', new DiscoveryResult([
			$this->hit('deck', 'github-search', 'Deck', 'GitHub summary', 'https://gh/icon.png'),
		]));
		$merge->add(AppStoreDiscovery::ID, new DiscoveryResult([
			$this->hit('deck', AppStoreDiscovery::ID, 'Deck', '', null),
		]));

		$result = $merge->results(false)[0];
		$this->assertSame('GitHub summary', $result['summary']);
		$this->assertSame('https://gh/icon.png', $result['iconUrl']);
	}

	public function testOtherProvidersNeverOverwriteTheFirstMetadata(): void {
		$merge = new DiscoveryMerge([]);
		$merge->add('github-search', new DiscoveryResult([
			$this->hit('deck', 'github-search', 'Deck', 'first', 'https://first/icon.png'),
			$this->hit('deck', 'github-private', 'Deck', 'second', 'https://second/icon.png'),
		]));

		$result = $merge->results(false)[0];
		$this->assertSame('first', $result['summary']);
		$this->assertSame('https://first/icon.png', $result['iconUrl']);
		$this->assertCount(2, $result['sourceCandidates']);
	}

	public function testInstalledAppsSortFirstThenByName(): void {
		$merge = new DiscoveryMerge(['zeta' => '1.0.0', 'beta' => '2.1.0']);
		$merge->add('p', new DiscoveryResult([
			$this->hit('gamma', 'p', 'Gamma'),
			$this->hit('zeta', 'p', 'Zeta'),
			$this->hit('alpha', 'p', 'Alpha'),
			$this->hit('beta', 'p', 'Beta'),
		]));

		$results = $merge->results(false);
		$this->assertSame(['beta', 'zeta', 'alpha', 'gamma'], array_column($results, 'appId'));
		$this->assertSame(['2.1.0', '1.0.0', null, null], array_column($results, 'installedVersion'));
	}

	public function testInstalledOnlyDropsAppsThatAreNotInstalled(): void {
		$merge = new DiscoveryMerge(['zeta' => '1.0.0']);
		$merge->add('p', new DiscoveryResult([
			$this->hit('alpha', 'p', 'Alpha'),
			$this->hit('zeta', 'p', 'Zeta'),
		]));

		$this->assertSame(['zeta'], array_column($merge->results(true), 'appId'));
		$this->assertSame(['zeta', 'alpha'], array_column($merge->results(false), 'appId'));
	}

	public function testProviderErrorsAreCollectedInOrderAndTheirHitsStillCount(): void {
		$merge = new DiscoveryMerge([]);
		$merge->add('appstore', DiscoveryResult::failed('Could not fetch App Store catalog.'));
		$merge->add('github-search', new DiscoveryResult([$this->hit('x', 'github-search', 'X')], 'rate limited'));
		$merge->add('github-private', DiscoveryResult::empty());

		$this->assertSame([
			['providerId' => 'appstore', 'message' => 'Could not fetch App Store catalog.'],
			['providerId' => 'github-search', 'message' => 'rate limited'],
		], $merge->errors());
		$this->assertSame(['x'], array_column($merge->results(false), 'appId'));
	}

	public function testNothingAddedGivesNoResultsAndNoErrors(): void {
		$merge = new DiscoveryMerge(['mail' => '1.0.0']);

		$this->assertSame([], $merge->results(false));
		$this->assertSame([], $merge->results(true));
		$this->assertSame([], $merge->errors());
	}
}
