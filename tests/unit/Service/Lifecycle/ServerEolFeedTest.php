<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

require_once __DIR__ . '/InMemoryAppConfig.php';

use OCA\Versioniq\Service\Lifecycle\ServerEolFeed;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
#[CoversClass(ServerEolFeed::class)]
final class ServerEolFeedTest extends TestCase {
	private const FEED = '[{"cycle":"32","eol":"2026-09-30"},{"cycle":"31","eol":"2026-03-31"},{"cycle":"33","eol":"2027-03-31"},{"cycle":"34","eol":false},{"cycle":"25","eol":true},{"cycle":"latest","eol":"2030-01-01"}]';

	/** @var list<string> */
	private array $requested = [];

	private \OCA\Versioniq\Tests\Unit\Service\Lifecycle\InMemoryAppConfig $store;

	private function feed(array $config = [], ?string $body = self::FEED, int $status = 200, int $now = 1794787200): ServerEolFeed {
		[$appConfig, $this->store] = InMemoryAppConfig::for($this, $config);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($body, $status): IResponse {
			$this->requested[] = $url;
			if ($body === null) {
				throw new \RuntimeException('connection refused');
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getBody')->willReturn($body);

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		$time->method('getDateTime')->willReturn(new \DateTime('@' . $now));

		return new ServerEolFeed($clients, $appConfig, $time, new NullLogger());
	}

	public function testTheFeedIsReadIntoMajorsAndDates(): void {
		$feed = $this->feed();

		$this->assertSame([25 => '1970-01-01', 31 => '2026-03-31', 32 => '2026-09-30', 33 => '2027-03-31', 34 => ''], $feed->majors());
		$this->assertSame([ServerEolFeed::DEFAULT_FEED_URL], $this->requested);
		$this->assertSame('2026-09-30', $feed->eolDate(32));
		$this->assertNull($feed->eolDate(34), 'a major without a date has no end-of-life date');
	}

	public function testMaintainedMajorsAreThoseWhoseDateHasNotPassed(): void {
		// 2026-11-15: 31 and 32 have passed, 33 is ahead, 34 has no date.
		$this->assertSame([33, 34], $this->feed()->maintainedMajors());
	}

	public function testAnAppIsOutOfSupportOnlyWhenNoneOfItsMajorsIsMaintained(): void {
		$feed = $this->feed();

		$this->assertTrue($feed->endOfSupport([31, 32]));
		$this->assertFalse($feed->endOfSupport([32, 33]));
		$this->assertNull($feed->endOfSupport([]), 'an app without known majors is unknown');
	}

	public function testAnEmptyAddressSwitchesTheFeedOff(): void {
		$feed = $this->feed([ServerEolFeed::KEY_FEED => '']);

		$this->assertNull($feed->feedUrl());
		$this->assertNull($feed->majors());
		$this->assertNull($feed->eolDate(32));
		$this->assertNull($feed->endOfSupport([31]), 'a switched-off feed never calls an app out of support');
		$this->assertSame([], $this->requested);
	}

	public function testAMirrorAddressIsRead(): void {
		$this->feed([ServerEolFeed::KEY_FEED => 'https://mirror.example/eol.json'])->majors();

		$this->assertSame(['https://mirror.example/eol.json'], $this->requested);
	}

	public function testAnUnreachableFeedIsUnknownNotEndOfSupport(): void {
		$feed = $this->feed([], null);

		$this->assertNull($feed->majors());
		$this->assertNull($feed->endOfSupport([31]));
		$this->assertArrayNotHasKey(ServerEolFeed::KEY_CACHE, $this->store->values, 'a failure is not cached');
	}

	public function testAnErrorStatusIsUnknown(): void {
		$this->assertNull($this->feed([], self::FEED, 503)->majors());
	}

	public function testAGoodAnswerIsKeptForADay(): void {
		$feed = $this->feed();
		$feed->majors();
		$feed->majors();

		$this->assertCount(1, $this->requested);

		$cache = $this->store->values[ServerEolFeed::KEY_CACHE];
		$stale = json_decode($cache, true);
		$stale['fetchedAt'] -= ServerEolFeed::CACHE_SECONDS;
		$this->feed([ServerEolFeed::KEY_CACHE => json_encode($stale)])->majors();
		$this->assertCount(2, $this->requested, 'an answer older than a day is read again');
	}
}
