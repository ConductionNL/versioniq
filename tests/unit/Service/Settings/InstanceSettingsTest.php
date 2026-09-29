<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Settings;

use InvalidArgumentException;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * #438 item 7: settings only `occ config:app:set` could change. The store
 * must write the SAME keys, with the SAME types, that their readers use
 * (PruneAuditJob, ArtifactCache, AppStoreSource, ForgeRegistry,
 * NextcloudAdvisoryFeed), or the page saves a value nothing reads.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/external-sources/spec.md
 */
final class InstanceSettingsTest extends TestCase {
	/** @var array<string, string|int> */
	private array $config = [];

	private function store(): InstanceSettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			},
		);

		return new InstanceSettings($appConfig);
	}

	public function testDefaultsWhenNothingIsSet(): void {
		$settings = $this->store()->read();

		self::assertSame(365, $settings['auditRetentionDays']);
		self::assertSame(30, $settings['auditRetentionMinDays']);
		self::assertSame(3, $settings['artifactCacheKeep']);
		self::assertSame('', $settings['appStoreApiBase']);
		self::assertSame('https://garm3.nextcloud.com/api/v1', $settings['appStoreApiDefault']);
		self::assertSame('', $settings['githubApiBase']);
		self::assertSame('https://api.github.com', $settings['githubApiDefault']);
		self::assertSame('', $settings['advisoryFeedUrl']);
	}

	public function testWritesTheKeysTheReadersUse(): void {
		$this->store()->update([
			'auditRetentionDays' => '90',
			'artifactCacheKeep' => '5',
			'appStoreApiBase' => 'https://store.example.org/api/v1/',
			'githubApiBase' => 'https://ghe.example.org/api/v3',
			'githubWebBase' => 'https://ghe.example.org',
			'advisoryFeedUrl' => 'http://mirror.internal/advisories',
		]);

		self::assertSame(90, $this->config['audit_retention_days']);
		self::assertSame(5, $this->config['artifact_cache_keep']);
		self::assertSame('https://store.example.org/api/v1', $this->config['appstore.api_base']);
		self::assertSame('https://ghe.example.org/api/v3', $this->config['forge.github.api_base']);
		self::assertSame('https://ghe.example.org', $this->config['forge.github.web_base']);
		self::assertSame('http://mirror.internal/advisories', $this->config['advisory.feed_base']);
	}

	public function testABlankUrlClearsTheOverride(): void {
		$this->config['appstore.api_base'] = 'https://store.example.org';

		$this->store()->update(['appStoreApiBase' => '  ']);

		self::assertArrayNotHasKey('appstore.api_base', $this->config);
	}

	public function testOmittedFieldsAreLeftAlone(): void {
		$this->config['audit_retention_days'] = 120;

		$this->store()->update(['artifactCacheKeep' => '2']);

		self::assertSame(120, $this->config['audit_retention_days']);
	}

	public function testRetentionBelowTheFloorIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->store()->update(['auditRetentionDays' => '7']);
	}

	public function testCacheKeepOutOfRangeIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->store()->update(['artifactCacheKeep' => '99']);
	}

	public function testGithubApiBaseMustBeHttpsBecauseTokensAreSentToIt(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->store()->update(['githubApiBase' => 'http://ghe.example.org/api/v3']);
	}

	public function testAUrlWithCredentialsIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->store()->update(['appStoreApiBase' => 'https://user:pw@store.example.org']);
	}

	public function testNothingIsWrittenWhenOneFieldIsInvalid(): void {
		try {
			$this->store()->update(['auditRetentionDays' => '90', 'artifactCacheKeep' => 'many']);
			self::fail('expected a rejection');
		} catch (InvalidArgumentException) {
		}

		self::assertSame([], $this->config);
	}

	/**
	 * inventory-pending-updates D3: how many release lines an app may fall
	 * behind, 0 to 10, empty for off.
	 *
	 * @spec openspec/changes/inventory-pending-updates/specs/pending-updates/spec.md
	 */
	public function testTheLagLimitIsOffUntilSetAndAcceptsZeroToTen(): void {
		$store = $this->store();
		self::assertNull($store->read()['maxLinesBehind']);
		self::assertNull($store->maxLinesBehind());

		$store->update(['maxLinesBehind' => '1']);
		self::assertSame(1, $store->read()['maxLinesBehind']);
		self::assertSame('1', $this->config[InstanceSettings::KEY_MAX_LINES_BEHIND]);

		$store->update(['maxLinesBehind' => '0']);
		self::assertSame(0, $store->maxLinesBehind());

		$store->update(['maxLinesBehind' => '10']);
		self::assertSame(10, $store->maxLinesBehind());

		$store->update(['maxLinesBehind' => '']);
		self::assertNull($store->maxLinesBehind());
		self::assertArrayNotHasKey(InstanceSettings::KEY_MAX_LINES_BEHIND, $this->config);
	}

	/**
	 * @spec openspec/changes/inventory-pending-updates/specs/pending-updates/spec.md
	 */
	public function testTheLagLimitRefusesElevenAndMinusOne(): void {
		foreach (['11', '-1', 'two'] as $raw) {
			try {
				$this->store()->update(['maxLinesBehind' => $raw]);
				self::fail('Accepted ' . $raw);
			} catch (InvalidArgumentException $error) {
				self::assertStringContainsString('between 0 and 10', $error->getMessage());
			}
		}
		self::assertArrayNotHasKey(InstanceSettings::KEY_MAX_LINES_BEHIND, $this->config);
	}
}
