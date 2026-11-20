<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Activity;

use OCA\Versioniq\Activity\Provider;
use OCA\Versioniq\Activity\Setting;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * How a version change reads in the activity stream (audit-attribution-and-forwarding
 * task 3.2), and the setting's defaults: on for the stream, off for mail.
 *
 * @covers \OCA\Versioniq\Activity\Provider
 * @covers \OCA\Versioniq\Activity\Setting
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-audit-rows-reach-the-platforms-audit-log-and-activity-stream
 */
final class ProviderTest extends TestCase {
	/** @var array<string, mixed> */
	private array $set = [];

	private function provider(): Provider {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('versioniq', 'en')->willReturn($l);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->with('versioniq', 'app-dark.svg')->willReturn('/apps/versioniq/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);

		return new Provider($factory, $urls);
	}

	/**
	 * @param array<string, string> $params
	 */
	private function event(string $subject, array $params, string $app = 'versioniq', string $type = Provider::TYPE): IEvent {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn($app);
		$event->method('getType')->willReturn($type);
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($params);
		$event->method('setRichSubject')->willReturnCallback(function (string $text, array $parameters) use ($event): IEvent {
			$this->set['rich'] = [$text, $parameters];

			return $event;
		});
		$event->method('setParsedSubject')->willReturnCallback(function (string $text) use ($event): IEvent {
			$this->set['parsed'] = $text;

			return $event;
		});
		$event->method('setIcon')->willReturnCallback(function (string $icon) use ($event): IEvent {
			$this->set['icon'] = $icon;

			return $event;
		});

		return $event;
	}

	public function testAPinNamesTheAdminTheAppAndTheVersion(): void {
		$this->provider()->parse('en', $this->event('pin', ['actor' => 'alice', 'app' => 'openregister', 'from' => '', 'to' => '2.3.0', 'level' => '']));

		self::assertSame('alice pinned openregister to 2.3.0', $this->set['parsed']);
		self::assertSame('{actor} pinned {app} to {to}', $this->set['rich'][0]);
		self::assertSame(['type' => 'user', 'id' => 'alice', 'name' => 'alice'], $this->set['rich'][1]['actor']);
		self::assertSame(['type' => 'highlight', 'id' => 'openregister', 'name' => 'openregister'], $this->set['rich'][1]['app']);
		self::assertSame('https://cloud.example/apps/versioniq/img/app-dark.svg', $this->set['icon']);
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
	 */
	public static function subjects(): array {
		$base = ['actor' => 'bob', 'app' => 'mail', 'from' => '5.0.0', 'to' => '5.1.0', 'level' => ''];

		return [
			'update' => ['install', $base, 'bob updated mail from 5.0.0 to 5.1.0'],
			'rollback' => ['rollback', ['from' => '5.1.0', 'to' => '5.0.0'] + $base, 'bob rolled back mail from 5.1.0 to 5.0.0'],
			'unpin' => ['unpin', $base, 'bob unpinned mail'],
			'policy set' => ['policy_set', ['level' => 'minor'] + $base, 'bob set the automatic update policy of mail to minor'],
			'policy cleared' => ['policy_clear', $base, 'bob cleared the automatic update policy of mail'],
			'the nightly job' => ['install', ['actor' => 'system'] + $base, 'system updated mail from 5.0.0 to 5.1.0'],
		];
	}

	/**
	 * @param array<string, string> $params
	 *
	 * @dataProvider subjects
	 */
	public function testEachSubjectReadsAsASentence(string $subject, array $params, string $expected): void {
		$this->provider()->parse('en', $this->event($subject, $params));

		self::assertSame($expected, $this->set['parsed']);
	}

	public function testTheSystemActorIsNotAUserLink(): void {
		$this->provider()->parse('en', $this->event('install', ['actor' => 'system', 'app' => 'mail', 'from' => '1', 'to' => '2']));

		self::assertSame('highlight', $this->set['rich'][1]['actor']['type']);
	}

	public function testAnotherAppsEventIsLeftToItsOwnProvider(): void {
		$this->expectException(UnknownActivityException::class);

		$this->provider()->parse('en', $this->event('pin', [], 'files'));
	}

	public function testAnUnknownSubjectIsRefused(): void {
		$this->expectException(UnknownActivityException::class);

		$this->provider()->parse('en', $this->event('delete_everything', []));
	}

	public function testTheSettingIsOnForTheStreamAndOffForMail(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$setting = new Setting($l);

		self::assertSame(Provider::TYPE, $setting->getIdentifier());
		self::assertSame('versioniq', $setting->getGroupIdentifier());
		self::assertTrue($setting->isDefaultEnabledStream());
		self::assertFalse($setting->isDefaultEnabledMail());
		self::assertTrue($setting->canChangeStream());
		self::assertTrue($setting->canChangeMail());
		self::assertSame('Versioniq', $setting->getGroupName());
		self::assertSame(70, $setting->getPriority());
		self::assertStringContainsString('pinned', $setting->getName());
	}
}
