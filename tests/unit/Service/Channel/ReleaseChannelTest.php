<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Channel;

use OCA\Versioniq\Service\Channel\ReleaseChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-every-listed-version-says-whether-it-is-a-pre-release
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
 */
final class ReleaseChannelTest extends TestCase {
	/**
	 * @param array<string, mixed> $entry
	 */
	#[DataProvider('preReleases')]
	public function testWhatAPreReleaseIs(array $entry, bool $expected): void {
		$this->assertSame($expected, ReleaseChannel::isPreRelease($entry));
	}

	/**
	 * @return array<string, array{array<string, mixed>, bool}>
	 */
	public static function preReleases(): array {
		return [
			'suffix' => [['version' => '2.6.0-beta.1'], true],
			'v-prefixed suffix' => [['version' => 'v2.6.0-rc.1'], true],
			'plain' => [['version' => '2.5.2'], false],
			'forge flag on a plain number' => [['version' => '2.5.0', 'sourcePreRelease' => true], true],
			'nightly flag' => [['version' => '2.5.0', 'sourcePreRelease' => true], true],
			'flag false' => [['version' => '2.5.0', 'sourcePreRelease' => false], false],
			'build metadata is not a suffix' => [['version' => '2.5.0+build.7'], false],
			'no version' => [[], false],
		];
	}

	#[DataProvider('channels')]
	public function testWhatEachChannelAdmits(string $channel, string $server, bool $plain, bool $pre): void {
		$lines = ['2.5'];
		$this->assertSame($plain, ReleaseChannel::admits($channel, ['version' => '2.5.3'], $server, $lines), 'plain version');
		$this->assertSame($pre, ReleaseChannel::admits($channel, ['version' => '2.6.0-beta.1'], $server, $lines), 'pre-release');
	}

	/**
	 * @return array<string, array{string, string, bool, bool}>
	 */
	public static function channels(): array {
		return [
			'server on stable' => ['server', 'stable', true, false],
			'server on production' => ['server', 'production', true, false],
			'server on enterprise' => ['server', 'Enterprise', true, false],
			'server on beta' => ['server', 'beta', true, true],
			'server on daily' => ['server', 'daily', true, true],
			'server on git' => ['server', 'git', true, true],
			'server on an unknown channel' => ['server', '', true, true],
			'stable' => ['stable', 'beta', true, false],
			'beta' => ['beta', 'stable', true, true],
			'lts on a marked line' => ['lts', 'beta', true, false],
		];
	}

	public function testLtsAdmitsOnlyMarkedLinesAndNothingWithoutOne(): void {
		$this->assertTrue(ReleaseChannel::admits('lts', ['version' => '2.3.9'], 'stable', ['2.3']));
		$this->assertFalse(ReleaseChannel::admits('lts', ['version' => '2.4.1'], 'stable', ['2.3']));
		$this->assertFalse(ReleaseChannel::admits('lts', ['version' => '2.30.0'], 'stable', ['2.3']));
		$this->assertTrue(ReleaseChannel::admits('lts', ['version' => 'v3.1.0'], 'stable', ['3']));
		$this->assertFalse(ReleaseChannel::admits('lts', ['version' => '2.3.10-rc.1'], 'beta', ['2.3']));
		$this->assertFalse(ReleaseChannel::admits('lts', ['version' => '2.3.9'], 'stable', []));
	}

	public function testPublisherMarkedLines(): void {
		$entries = [
			['version' => '2.4.0'],
			['version' => '2.3.9', 'sourceLts' => true],
			['version' => '2.3.8', 'sourceLts' => true],
			['version' => '1', 'sourceLts' => true],
			['version' => 'garbage', 'sourceLts' => true],
		];

		$this->assertSame(['2.3', '1'], ReleaseChannel::publisherLines($entries));
	}

	public function testKnownChannelsAndLines(): void {
		foreach (['server', 'stable', 'beta', 'lts'] as $channel) {
			$this->assertTrue(ReleaseChannel::isKnown($channel));
		}
		$this->assertFalse(ReleaseChannel::isKnown('betta'));
		$this->assertFalse(ReleaseChannel::isKnown(''));
		$this->assertTrue(ReleaseChannel::isLine('2'));
		$this->assertTrue(ReleaseChannel::isLine('2.3'));
		$this->assertFalse(ReleaseChannel::isLine('2.3.1'));
		$this->assertFalse(ReleaseChannel::isLine('v2'));
	}
}
