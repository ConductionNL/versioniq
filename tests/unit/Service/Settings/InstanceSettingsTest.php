<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

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
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->config[$key] ?? $default),
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
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
	 * @spec openspec/specs/pending-updates/spec.md
	 */
	/**
	 * install-manual-upload D4: unsigned uploads are off until an admin turns them on.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.3
	 */
	public function testUnsignedUploadsAreOffUntilTurnedOn(): void {
		$store = $this->store();
		self::assertFalse($store->read()['allowUnsignedUpload']);
		self::assertFalse($store->allowsUnsignedUpload());

		self::assertSame(['allowUnsignedUpload'], $store->update(['allowUnsignedUpload' => 'true']));
		self::assertTrue($this->config[InstanceSettings::KEY_ALLOW_UNSIGNED_UPLOAD]);
		self::assertSame('install.allow_unsigned_upload', InstanceSettings::KEY_ALLOW_UNSIGNED_UPLOAD);
		self::assertTrue($store->read()['allowUnsignedUpload']);

		$store->update(['allowUnsignedUpload' => ' 0 ']);
		self::assertFalse($store->allowsUnsignedUpload());
		$store->update(['allowUnsignedUpload' => 'TRUE']);
		self::assertTrue($store->allowsUnsignedUpload());
		$store->update(['allowUnsignedUpload' => 'false']);
		self::assertFalse($store->allowsUnsignedUpload());
	}

	/**
	 * Anything but true, false, 1 or 0 is refused, and nothing is written.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.3
	 */
	public function testTheUnsignedUploadSwitchRefusesOtherValues(): void {
		foreach (['yes', '', 'on', '2'] as $value) {
			try {
				$this->store()->update(['allowUnsignedUpload' => $value, 'auditRetentionDays' => '90']);
				self::fail('accepted ' . var_export($value, true));
			} catch (InvalidArgumentException $error) {
				self::assertSame('Allow unsigned uploads must be true or false.', $error->getMessage());
			}
		}

		self::assertArrayNotHasKey(InstanceSettings::KEY_ALLOW_UNSIGNED_UPLOAD, $this->config);
		self::assertArrayNotHasKey('audit_retention_days', $this->config);
	}

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
	 * @spec openspec/specs/pending-updates/spec.md
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

	/**
	 * Every rule of an address field, for the https-only GitHub API base and
	 * the http-or-https advisory feed: the stored value, or the message.
	 *
	 * @dataProvider addresses
	 */
	public function testEveryAddressRule(string $field, string $raw, string $expected): void {
		$key = ['githubApiBase' => 'forge.github.api_base', 'advisoryFeedUrl' => 'advisory.feed_base'][$field];
		try {
			$this->store()->update([$field => $raw]);
			$actual = (string)($this->config[$key] ?? '(cleared)');
		} catch (InvalidArgumentException $error) {
			$actual = $error->getMessage();
		}

		self::assertSame($expected, $actual);
	}

	/**
	 * @return iterable<string, array{0: string, 1: string, 2: string}>
	 */
	public static function addresses(): iterable {
		$https = 'Enter an https address without a username, password or query, for example https://ghe.example.org/api/v3.';
		$http = 'Enter an http or https address without a username, password or query.';
		foreach (['githubApiBase' => $https, 'advisoryFeedUrl' => $http] as $field => $refusal) {
			yield "$field trailing slashes trimmed" => [$field, '  https://ghe.example.org/api/v3// ', 'https://ghe.example.org/api/v3'];
			yield "$field http" => [$field, 'http://ghe.example.org', $field === 'githubApiBase' ? $https : 'http://ghe.example.org'];
			yield "$field ftp" => [$field, 'ftp://ghe.example.org', $refusal];
			yield "$field no scheme" => [$field, 'ghe.example.org', $refusal];
			yield "$field no host" => [$field, 'https:///path', $refusal];
			yield "$field user" => [$field, 'https://bot@ghe.example.org', $refusal];
			yield "$field password" => [$field, 'https://:pw@ghe.example.org', $refusal];
			yield "$field query" => [$field, 'https://ghe.example.org/?a=1', $refusal];
			yield "$field fragment" => [$field, 'https://ghe.example.org/#top', $refusal];
			yield "$field port and path" => [$field, 'https://ghe.example.org:8443/api', 'https://ghe.example.org:8443/api'];
			yield "$field unparseable" => [$field, 'https://', $refusal];
		}
	}
}
