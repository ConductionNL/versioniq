<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\Service\Discovery\PublisherTrust;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
 */
final class PublisherTrustTest extends TestCase {
	private const NOW = 2_000_000_000;

	/** @var array<string, string> */
	private array $config = [];

	/** @var list<string> */
	private array $requested = [];

	/**
	 * @param array<string, array{0: int, 1: string}> $github Status and body per owner.
	 * @param list<string> $shipped
	 * @param list<string> $trusted Allowed source ids.
	 */
	private function trust(array $github = [], array $shipped = [], array $trusted = []): PublisherTrust {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isShipped')->willReturnCallback(static fn (string $id): bool => in_array($id, $shipped, true));

		$allow = $this->createMock(TrustedSourceList::class);
		$allow->method('isAllowed')->willReturnCallback(static fn (string $id): bool => in_array($id, $trusted, true));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($github): IResponse {
			$this->requested[] = $url;
			$owner = substr($url, strlen('https://api.github.com/orgs/'));
			if (!isset($github[$owner])) {
				throw new \RuntimeException('offline');
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($github[$owner][0]);
			$response->method('getBody')->willReturn($github[$owner][1]);

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new PublisherTrust($appManager, $allow, $clients, $config, $time, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array<string, string>
	 */
	private static function github(string $owner, string $repo): array {
		return ['kind' => 'github-release', 'owner' => $owner, 'repo' => $repo];
	}

	public function testAShippedAppIsMarkedShipped(): void {
		$marks = $this->trust(shipped: ['files'])->marksFor('files', ['kind' => 'appstore'], false);

		$this->assertSame([PublisherTrust::MARK_SHIPPED], $marks);
		$this->assertSame([], $this->requested, 'an App Store binding never asks GitHub');
	}

	public function testAFeaturedAppIsMarkedFeatured(): void {
		$this->assertSame([PublisherTrust::MARK_FEATURED], $this->trust()->marksFor('whiteboard', ['kind' => 'appstore'], true));
	}

	public function testAVerifiedOrganisationIsMarkedAndCachedForAWeek(): void {
		$trust = $this->trust(github: ['nextcloud' => [200, '{"login":"nextcloud","is_verified":true}']]);

		$this->assertSame([PublisherTrust::MARK_VERIFIED_ORG], $trust->marksFor('deck', self::github('nextcloud', 'deck'), false));
		$this->assertSame(['verified' => true, 'at' => self::NOW], json_decode($this->config['publisher.github.nextcloud'], true));

		$again = $this->trust(github: []);
		$this->assertTrue($again->isVerifiedOrganisation('Nextcloud'));
		$this->assertCount(1, $this->requested, 'the cached answer is reused');
	}

	public function testAnUnverifiedOrganisationGetsNoMark(): void {
		$trust = $this->trust(github: ['someorg' => [200, '{"login":"someorg","is_verified":false}']]);

		$this->assertSame([], $trust->marksFor('thing', self::github('someorg', 'thing'), false));
	}

	public function testAUserAccountIsCachedAsNotAnOrganisation(): void {
		$trust = $this->trust(github: ['someone' => [404, '{"message":"Not Found"}']]);

		$this->assertFalse($trust->isVerifiedOrganisation('someone'));
		$this->assertSame(['verified' => false, 'at' => self::NOW], json_decode($this->config['publisher.github.someone'], true));
	}

	public function testAFailedCallIsNotCachedAndAskedAgain(): void {
		$this->assertFalse($this->trust(github: [])->isVerifiedOrganisation('offline'));
		$this->assertArrayNotHasKey('publisher.github.offline', $this->config);

		$this->assertFalse($this->trust(github: ['offline' => [500, '']])->isVerifiedOrganisation('offline'));
		$this->assertArrayNotHasKey('publisher.github.offline', $this->config);
		$this->assertCount(2, $this->requested);
	}

	public function testAStaleCacheIsAskedAgain(): void {
		$this->config['publisher.github.acme'] = json_encode(['verified' => false, 'at' => self::NOW - PublisherTrust::CACHE_TTL_SECONDS]);

		$this->assertTrue($this->trust(github: ['acme' => [200, '{"is_verified":true}']])->isVerifiedOrganisation('acme'));
		$this->assertCount(1, $this->requested);
	}

	public function testAnInvalidOwnerIsNeverSentToGithub(): void {
		$this->assertFalse($this->trust()->isVerifiedOrganisation('../users/x'));
		$this->assertSame([], $this->requested);
	}

	public function testATrustedSourceIsMarkedTrusted(): void {
		$trust = $this->trust(github: ['ConductionNL' => [200, '{"is_verified":true}']], trusted: ['github:ConductionNL/versioniq']);

		$this->assertSame(
			[PublisherTrust::MARK_VERIFIED_ORG, PublisherTrust::MARK_TRUSTED],
			$trust->marksFor('versioniq', self::github('ConductionNL', 'versioniq'), false)
		);
	}

	public function testThePublisherIsTheAuthorNamesWithoutAnythingElse(): void {
		$this->assertSame('Nextcloud GmbH, A. Person', PublisherTrust::publisherFromAuthors([
			['name' => 'Nextcloud GmbH', 'mail' => 'x@example.org'],
			['name' => ' A. Person ', 'homepage' => 'https://example.org'],
		]));
		$this->assertSame([['name' => 'Solo']], PublisherTrust::authorNames(['name' => 'Solo', 'mail' => 'solo@example.org']));
		$this->assertNull(PublisherTrust::publisherFromAuthors(null));
		$this->assertNull(PublisherTrust::publisherFromAuthors([['mail' => 'only@example.org']]));
	}
}
