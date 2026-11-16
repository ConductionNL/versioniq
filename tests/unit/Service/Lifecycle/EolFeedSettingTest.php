<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

require_once __DIR__ . '/InMemoryAppConfig.php';

use InvalidArgumentException;
use OCA\Versioniq\Service\Lifecycle\ServerEolFeed;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The end-of-life feed address is an instance setting an admin can change or empty.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
#[CoversClass(InstanceSettings::class)]
final class EolFeedSettingTest extends TestCase {
	public function testAnEmptyAddressIsKeptAndSwitchesTheFeedOff(): void {
		[$config, $store] = InMemoryAppConfig::for($this);
		$settings = new InstanceSettings($config);

		$this->assertSame(['eolFeedUrl'], $settings->update(['eolFeedUrl' => '  ']));
		$this->assertSame('', $store->values[ServerEolFeed::KEY_FEED]);
		$this->assertTrue($settings->read()['eolFeedOff']);
	}

	public function testAMirrorIsStoredAndTheDefaultIsShown(): void {
		[$config, $store] = InMemoryAppConfig::for($this);
		$settings = new InstanceSettings($config);

		$this->assertFalse($settings->read()['eolFeedOff'], 'unset reads the default feed');
		$settings->update(['eolFeedUrl' => 'https://mirror.example/eol.json/']);

		$this->assertSame('https://mirror.example/eol.json', $store->values[ServerEolFeed::KEY_FEED]);
		$this->assertSame('https://mirror.example/eol.json', $settings->read()['eolFeedUrl']);
		$this->assertSame(ServerEolFeed::DEFAULT_FEED_URL, $settings->read()['eolFeedDefault']);
	}

	public function testAnAddressWithCredentialsIsRefusedAndNothingIsWritten(): void {
		[$config, $store] = InMemoryAppConfig::for($this);

		try {
			(new InstanceSettings($config))->update(['eolFeedUrl' => 'https://user:secret@mirror.example/eol.json']);
			$this->fail('an address with credentials must be refused');
		} catch (InvalidArgumentException) {
			$this->assertArrayNotHasKey(ServerEolFeed::KEY_FEED, $store->values);
		}
	}
}
