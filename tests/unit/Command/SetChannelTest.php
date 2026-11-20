<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-channel-is-visible-and-settable-from-the-cli
 */

namespace OCA\Versioniq\Tests\Unit\Command;

use OCA\Versioniq\Command\SetChannel;
use OCA\Versioniq\Service\Channel\ChannelStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ versioniq:channel`: reads every channel, sets the default, an app's channel and lines, refuses unknown values without a change.
 */
final class SetChannelTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];

	private function tester(): CommandTester {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			},
		);
		$appConfig->method('getKeys')->willReturnCallback(fn (string $app): array => array_keys($this->config));

		return new CommandTester(new SetChannel(new ChannelStore($appConfig)));
	}

	public function testWithoutArgumentsItPrintsTheDefaultAndEveryAppsChannel(): void {
		$this->config = ['channel.default' => 'stable', 'channel.hermiq' => 'beta', 'channel.deck' => 'lts', 'channel.bad' => 'nightly'];
		$tester = $this->tester();

		self::assertSame(0, $tester->execute([]));
		self::assertSame("Default: stable\ndeck: lts\nhermiq: beta\n", $tester->getDisplay());
	}

	public function testItSetsTheDefault(): void {
		$tester = $this->tester();

		self::assertSame(0, $tester->execute(['appId' => 'default', 'channel' => 'beta']));
		self::assertSame(['channel.default' => 'beta'], $this->config);
		self::assertStringContainsString('Default: beta', $tester->getDisplay());
	}

	public function testItSetsReadsAndClearsAnAppsChannel(): void {
		$tester = $this->tester();

		self::assertSame(0, $tester->execute(['appId' => 'hermiq', 'channel' => 'lts', '--lts-lines' => '2.3, 2.4']));
		self::assertSame(['channel.hermiq' => 'lts', 'lts_lines.hermiq' => '["2.3","2.4"]'], $this->config);
		self::assertSame("hermiq: lts\nLong-term support lines: 2.3, 2.4\n", $tester->getDisplay());

		self::assertSame(0, $tester->execute(['appId' => 'hermiq', 'channel' => 'default']));
		self::assertArrayNotHasKey('channel.hermiq', $this->config);
		self::assertSame("hermiq: server (the default)\nLong-term support lines: 2.3, 2.4\n", $tester->getDisplay());

		self::assertSame(0, $tester->execute(['appId' => 'hermiq', '--lts-lines' => '']));
		self::assertSame([], $this->config);
	}

	public function testAnUnknownChannelExitsNonZeroWithoutAChange(): void {
		$this->config = ['channel.hermiq' => 'beta'];
		$tester = $this->tester();

		self::assertSame(1, $tester->execute(['appId' => 'hermiq', 'channel' => 'nightly']));
		self::assertStringContainsString('Unknown release channel: nightly', $tester->getDisplay());
		self::assertSame(1, $tester->execute(['appId' => 'default', 'channel' => 'default']));
		self::assertSame(['channel.hermiq' => 'beta'], $this->config);
	}

	public function testABadLineExitsNonZeroWithoutChangingTheChannelEither(): void {
		$tester = $this->tester();

		self::assertSame(1, $tester->execute(['appId' => 'hermiq', 'channel' => 'lts', '--lts-lines' => '2.3,latest']));
		self::assertStringContainsString('A long-term support line is a major or major.minor version.', $tester->getDisplay());
		self::assertSame(1, $tester->execute(['--lts-lines' => '2.3']));
		self::assertStringContainsString('--lts-lines needs the app whose lines they are.', $tester->getDisplay());
		self::assertSame([], $this->config);
	}
}
