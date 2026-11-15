<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Settings;

use InvalidArgumentException;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Golden over InstanceSettings::update(): the applied field names (or the
 * refusal) and every configuration write, per case, compared with
 * fixtures/instance-settings-update.golden.json. Set RECORD_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\Settings\InstanceSettings
 */
final class InstanceSettingsUpdateGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/instance-settings-update.golden.json';

	/** @var list<list<mixed>> */
	private array $writes = [];

	/**
	 * @return array<string, array<string, string>>
	 */
	private static function cases(): array {
		return [
			'nothing' => [],
			'retention' => ['auditRetentionDays' => '90'],
			'retention too low' => ['auditRetentionDays' => '1'],
			'retention not a number' => ['auditRetentionDays' => 'many'],
			'cache keep' => ['artifactCacheKeep' => ' 5 '],
			'cache keep too high' => ['artifactCacheKeep' => '21'],
			'lag limit' => ['maxLinesBehind' => '3'],
			'lag limit cleared' => ['maxLinesBehind' => '  '],
			'lag limit out of range' => ['maxLinesBehind' => '11'],
			'urls set and cleared' => ['appStoreApiBase' => 'http://store.example.org/api/v1', 'githubApiBase' => '', 'githubWebBase' => 'https://github.example.org', 'advisoryFeedUrl' => 'https://feed.example.org/advisories.json'],
			'github api over http refused' => ['githubApiBase' => 'http://api.example.org'],
			'not a url' => ['advisoryFeedUrl' => 'feed'],
			'one bad field writes nothing' => ['auditRetentionDays' => '30', 'artifactCacheKeep' => '3', 'maxLinesBehind' => '2', 'githubWebBase' => 'ftp://x'],
			'everything' => ['auditRetentionDays' => '365', 'artifactCacheKeep' => '0', 'maxLinesBehind' => '0', 'appStoreApiBase' => 'https://apps.example.org/api/v1', 'githubApiBase' => 'https://api.example.org', 'githubWebBase' => '', 'advisoryFeedUrl' => '', 'unknown' => 'x'],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$this->writes = [];
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->writes[] = ['int', $app, $key, $value];

			return true;
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->writes[] = ['string', $app, $key, $value];

			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			$this->writes[] = ['delete', $app, $key];
		});

		try {
			$result = ['applied' => (new InstanceSettings($config))->update(self::cases()[$name])];
		} catch (InvalidArgumentException $error) {
			$result = ['refused' => $error->getMessage()];
		}
		$actual = json_encode($result + ['writes' => $this->writes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}
}
