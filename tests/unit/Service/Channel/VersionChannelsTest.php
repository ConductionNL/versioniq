<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Channel;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Channel\ChannelStore;
use OCA\Versioniq\Service\Channel\VersionChannels;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-every-listed-version-says-whether-it-is-a-pre-release
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
 */
final class VersionChannelsTest extends TestCase {
	/**
	 * @param list<string> $lines
	 */
	private function channels(string $channel, string|\Throwable $server, array $lines = []): VersionChannels {
		$store = $this->createMock(ChannelStore::class);
		$store->method('effectiveChannel')->willReturn($channel);
		$store->method('ltsLines')->willReturn($lines);
		$provider = $this->createMock(ServerVersionProvider::class);
		if ($server instanceof \Throwable) {
			$provider->method('channel')->willThrowException($server);
		} else {
			$provider->method('channel')->willReturn($server);
		}

		return new VersionChannels($store, $provider);
	}

	public function testEveryEntryIsStampedAndNoneIsDropped(): void {
		$versions = [
			['version' => '2.6.0-beta.1', 'changelog' => null],
			['version' => '2.5.0', 'changelog' => 'notes', 'sourcePreRelease' => true],
			['version' => '2.4.3', 'changelog' => null],
		];

		$result = $this->channels('server', 'stable')->stamp('hermiq', $versions);

		$this->assertSame('server', $result['channel']);
		$this->assertSame(['2.6.0-beta.1', '2.5.0', '2.4.3'], array_column($result['versions'], 'version'));
		$this->assertSame([true, true, false], array_column($result['versions'], 'preRelease'));
		$this->assertSame([false, false, true], array_column($result['versions'], 'onChannel'));
		$this->assertSame('notes', $result['versions'][1]['changelog']);
	}

	public function testABetaAppTakesItsPreReleases(): void {
		$result = $this->channels('beta', 'stable')->stamp('hermiq', [['version' => '2.5.0-rc.1']]);

		$this->assertSame('beta', $result['channel']);
		$this->assertTrue($result['versions'][0]['onChannel']);
	}

	public function testLtsCombinesMarkedAndPublisherLines(): void {
		$versions = [
			['version' => '2.5.0'],
			['version' => '2.4.1', 'sourceLts' => true],
			['version' => '2.4.0'],
			['version' => '2.3.9'],
			['version' => '2.2.0'],
		];

		$result = $this->channels('lts', 'stable', ['2.3'])->stamp('openregister', $versions);

		$this->assertSame([false, true, true, true, false], array_column($result['versions'], 'lts'));
		$this->assertSame([false, true, true, true, false], array_column($result['versions'], 'onChannel'));
	}

	public function testLtsWithNoLineAdmitsNothing(): void {
		$result = $this->channels('lts', 'stable')->stamp('calendar', [['version' => '5.0.0'], ['version' => '4.7.1']]);

		$this->assertSame([false, false], array_column($result['versions'], 'onChannel'));
	}

	public function testAnUnreadableServerChannelAdmitsEverythingOnServer(): void {
		$result = $this->channels('server', new \RuntimeException('no version.php'))->stamp('hermiq', [['version' => '2.6.0-beta.1']]);

		$this->assertTrue($result['versions'][0]['onChannel']);
	}
}
