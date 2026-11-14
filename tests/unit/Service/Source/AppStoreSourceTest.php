<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/changelog-visibility/spec.md
 */
final class AppStoreSourceTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * @param array<string, mixed> $appPayload
	 */
	private function buildSource(array $appPayload, string $language = 'en'): AppStoreSource {
		$body = json_encode(['data' => [$appPayload]], JSON_THROW_ON_ERROR);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');
		$appConfig = $this->createMock(IAppConfig::class);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn($language);

		return $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);
	}

	private function binding(): SourceBinding {
		return SourceBinding::appStore();
	}

	public function testMapsRequestedLanguageChangelog(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					'translations' => [
						'en' => ['changelog' => 'English notes'],
						'nl' => ['changelog' => 'Nederlandse notities'],
					],
				],
			],
		], 'nl');

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertNull($result['error']);
		$this->assertSame('Nederlandse notities', $result['versions'][0]['changelog']);
	}

	/**
	 * Each App Store release carries its `platformVersionSpec`; the listing
	 * says whether the running server (28.0.0 in buildSource) satisfies it
	 * (issue #435).
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testMarksEachReleaseWithWhetherItRunsOnThisServer(): void {
		$source = $this->buildSource([
			'id' => 'deck',
			'releases' => [
				['version' => '1.13.0', 'platformVersionSpec' => '>=26.0.0 <=28'],
				['version' => '1.14.0', 'platformVersionSpec' => '>=29.0.0 <=30'],
				['version' => '1.12.0', 'platformVersionSpec' => '>=25.0.0 <28.0.0'],
				['version' => '1.11.0', 'platformVersionSpec' => '*'],
				['version' => '1.10.0'],
				['version' => '1.9.0', 'platformVersionSpec' => 'not a spec'],
			],
		]);

		$result = $source->listVersions('deck', $this->binding());

		$byVersion = [];
		foreach ($result['versions'] as $entry) {
			$byVersion[$entry['version']] = array_key_exists('serverCompatible', $entry) ? $entry['serverCompatible'] : 'missing';
		}
		$this->assertSame([
			'1.14.0' => false,
			'1.13.0' => true,
			'1.12.0' => false,
			'1.11.0' => true,
			'1.10.0' => null,
			'1.9.0' => null,
		], $byVersion);
	}

	/**
	 * Versioniq#480: every App Store version carries its release date from the
	 * release's `created`, normalised to ISO 8601 UTC, or null when absent or unreadable.
	 *
	 * @spec openspec/changes/releases-version-facts/tasks.md#task-1.1
	 */
	public function testEachVersionCarriesItsReleaseDate(): void {
		$source = $this->buildSource([
			'id' => 'deck',
			'releases' => [
				['version' => '1.14.0', 'created' => '2026-03-05T10:15:30.123456Z'],
				['version' => '1.13.0', 'created' => '2026-01-02T08:00:00+01:00'],
				['version' => '1.12.0'],
				['version' => '1.11.0', 'created' => 'not a date'],
			],
		]);

		$result = $source->listVersions('deck', $this->binding());

		$byVersion = [];
		foreach ($result['versions'] as $entry) {
			$byVersion[$entry['version']] = array_key_exists('releasedAt', $entry) ? $entry['releasedAt'] : 'missing';
		}
		$this->assertSame([
			'1.14.0' => '2026-03-05T10:15:30Z',
			'1.13.0' => '2026-01-02T07:00:00Z',
			'1.12.0' => null,
			'1.11.0' => null,
		], $byVersion);
	}

	public function testFallsBackToEnglishWhenRequestedLanguageMissing(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					'translations' => [
						'en' => ['changelog' => 'English notes'],
					],
				],
			],
		], 'de');

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertSame('English notes', $result['versions'][0]['changelog']);
	}

	public function testMissingTranslationsYieldsNullChangelogAndListingSucceeds(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				['version' => '2.3.0'],
			],
		]);

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertNull($result['error']);
		$this->assertCount(1, $result['versions']);
		$this->assertNull($result['versions'][0]['changelog']);
	}

	public function testMalformedTranslationsShapeIsFailSoftNull(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					// Malformed: translations should be a map, not a string.
					'translations' => 'not-an-array',
				],
			],
		]);

		$result = $source->listVersions('openregister', $this->binding());

		// The throwing mapper must not fail the whole listing.
		$this->assertNull($result['error']);
		$this->assertCount(1, $result['versions']);
		$this->assertSame('2.3.0', $result['versions'][0]['version']);
		$this->assertNull($result['versions'][0]['changelog']);
	}

	public function testBlankChangelogNormalizesToNull(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					'translations' => [
						'en' => ['changelog' => "   \n  "],
					],
				],
			],
		]);

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertNull($result['versions'][0]['changelog']);
	}

	/**
	 * The App Store catalogue endpoint ignores its `filter` parameter and returns
	 * ~30 MB for every call, so a resolved payload must be reused rather than
	 * refetched on the next listing.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testResolvedPayloadIsCachedAndNotRefetched(): void {
		$body = json_encode([
			'data' => [[
				'id' => 'openregister',
				'releases' => [['version' => '2.3.0']],
			]],
		], JSON_THROW_ON_ERROR);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		// The catalogue must be fetched exactly once across two listings.
		$client->expects($this->once())->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		// An in-memory app-config double so the second call sees the first write.
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;

				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			},
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		$source = $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);

		$first = $source->listVersions('openregister', $this->binding());
		$second = $source->listVersions('openregister', $this->binding());

		$this->assertSame('2.3.0', $first['versions'][0]['version']);
		$this->assertSame($first['versions'], $second['versions'], 'cached listing must match the fetched one');
	}

	/**
	 * The payload cache must be LAZY app config, on write and on read.
	 *
	 * Nextcloud preloads every eager app-config row of every app on every
	 * request. Written eagerly, this cache put 36 MB into that preload and
	 * ~300 ms onto every request of the instance (measured 2026-10-06). A
	 * test that only checks the round trip passes either way, so this one
	 * records the flag each call carries.
	 *
	 * @spec exclude Storage flag of an internal cache; the version-management
	 *  spec says the payload is cached, not how the row is stored.
	 */
	public function testPayloadCacheIsReadAndWrittenLazy(): void {
		$body = json_encode([
			'data' => [[
				'id' => 'openregister',
				'releases' => [['version' => '2.3.0']],
			]],
		], JSON_THROW_ON_ERROR);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$store = [];
		$writes = [];
		$reads = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false) use (&$store, &$writes): bool {
				$store[$key] = $value;
				$writes[$key] = $lazy;

				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '', bool $lazy = false) use (&$store, &$reads): string {
				$reads[$key] = $lazy;

				return $store[$key] ?? $default;
			},
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		$source = $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);
		$source->listVersions('openregister', $this->binding());
		$source->listVersions('openregister', $this->binding());

		foreach (['appstore.payload.openregister', 'appstore.payload_ts.openregister'] as $key) {
			$this->assertTrue($writes[$key] ?? false, $key . ' must be written lazy');
			$this->assertTrue($reads[$key] ?? false, $key . ' must be read lazy');
		}
	}

	public function testExpiredCacheIsRefetched(): void {
		$body = json_encode([
			'data' => [['id' => 'openregister', 'releases' => [['version' => '2.3.0']]]],
		], JSON_THROW_ON_ERROR);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		// Stale timestamp ⇒ both listings must hit the network.
		$client->expects($this->exactly(2))->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($body): string {
				// Payload present but cached long ago.
				if (str_starts_with($key, 'appstore.payload_ts.')) {
					return '1';
				}
				if (str_starts_with($key, 'appstore.payload.')) {
					return $body;
				}
				return $default;
			},
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		$source = $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);
		$source->listVersions('openregister', $this->binding());
		$source->listVersions('openregister', $this->binding());
	}

	public function testStaleCacheServedWhenRefetchFails(): void {
		// A good payload was cached long ago (TTL lapsed), and the live App Store
		// is having an outage — here a 200 with an empty body, the exact episode
		// observed against garm3. The listing must serve the stale copy rather
		// than blank out; a flaky upstream is why the cache exists.
		//
		// THE CACHED SHAPE IS THE EXTRACTED APP PAYLOAD, NOT THE API ENVELOPE.
		// `fetchAppPayload()` caches whatever `extractAppPayload()` returned —
		// the inner `{id, releases}` object — so seeding the outer
		// `{"data": [...]}` envelope here fixtured a shape this app never
		// writes. `readCachedPayload()` handed the envelope straight back, the
		// version mapping found no `releases` at the top level, and the
		// assertion read null. The stale-if-error path in the code is correct;
		// the fixture was describing a different cache.
		$body = json_encode(
			['id' => 'openregister', 'releases' => [['version' => '2.3.0']]],
			JSON_THROW_ON_ERROR,
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(''); // upstream returns nothing

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($body): string {
				if (str_starts_with($key, 'appstore.payload_ts.')) {
					return '1'; // cached at epoch 1 ⇒ TTL long lapsed
				}
				if (str_starts_with($key, 'appstore.payload.')) {
					return $body;
				}
				return $default;
			},
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		$source = $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);
		$result = $source->listVersions('openregister', $this->binding());

		$this->assertSame('2.3.0', $result['versions'][0]['version'], 'stale cache must serve during an upstream outage');
	}

	/**
	 * ONE DOWNLOAD SERVES THE WHOLE SWEEP.
	 *
	 * The App Store ignores `?filter=`, so a lookup for one app already
	 * downloads every app (measured: 755 entries, 31.7 MB). Before this, the
	 * other 754 were discarded, so an advisory sweep over 88 enabled apps
	 * re-downloaded the catalogue per app.
	 *
	 * The assertion is on HTTP calls, not on elapsed time: the second app must
	 * be answered without touching the network at all.
	 */
	public function testCachesEveryAppInTheCatalogueSoASweepDownloadsItOnce(): void {
		$catalogue = ['data' => [
			['id' => 'notes', 'releases' => [['version' => '4.13.0']]],
			['id' => 'calendar', 'releases' => [['version' => '5.4.0']]],
			['id' => 'deck', 'releases' => [['version' => '1.14.0']]],
		]];

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode($catalogue, JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		// THE ASSERTION: exactly one GET for three apps.
		$client->expects($this->once())->method('get')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		// An in-memory app config, so a cache write by one lookup is visible to
		// the next — which is the whole mechanism under test.
		$store = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$store): bool {
				$store[$key] = $value;

				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			},
		);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		$source = $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);

		$first = $source->listVersions('notes', $this->binding());
		$second = $source->listVersions('calendar', $this->binding());
		$third = $source->listVersions('deck', $this->binding());

		// Each app must still get ITS OWN payload — a shared cache that returned
		// the first app's data for every lookup would also satisfy the call
		// count above, so assert the versions differ.
		$this->assertSame('4.13.0', $first['versions'][0]['version']);
		$this->assertSame('5.4.0', $second['versions'][0]['version'], 'the second app must be served from cache, with its own payload');
		$this->assertSame('1.14.0', $third['versions'][0]['version'], 'the third app must be served from cache, with its own payload');
	}

	/**
	 * Issue #443: an advisory without a severity reads `unknown` from every
	 * source, as AdvisorySourceInterface documents, instead of `medium` here
	 * and `unknown` from the Nextcloud feed.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	public function testAnAdvisoryWithoutASeverityIsUnknown(): void {
		$source = $this->buildSource([
			'id' => 'deck',
			'releases' => [['version' => '1.0.0']],
			'securityAdvisories' => [
				['id' => 'NC-1', 'summary' => 'Unscored'],
				['id' => 'NC-2', 'summary' => 'Scored', 'severity' => 'HIGH'],
				['id' => 'NC-3', 'summary' => 'GitHub wording', 'severity' => 'moderate'],
			],
		]);

		$result = $source->listAdvisories('deck', $this->binding());

		$this->assertSame(['unknown', 'high', 'medium'], array_column($result['advisories'], 'severity'));
	}

	/**
	 * Which spec an override can lift: only one whose maximum is below the
	 * server while every lower bound holds (install-force-incompatible D1).
	 *
	 * @return array<string, array{0: string, 1: string, 2: ?bool}>
	 */
	public static function aboveMaximumCases(): array {
		return [
			'maximum below the server' => ['33.0.2', '>=30 <=32', true],
			'strict maximum reached' => ['33.0.2', '>=30.0.0 <33.0.0', true],
			'minimum above the server' => ['32.0.5', '>=33 <=34', false],
			'both bounds miss' => ['33.0.2', '>=34 <=32', false],
			'inside the range' => ['32.0.5', '>=30 <=32', false],
			'any server' => ['33.0.2', '*', false],
			'exact other major above' => ['33.0.2', '==32', true],
			'exact other major below' => ['31.0.2', '==32', false],
			'unreadable spec' => ['33.0.2', '>=30 foo', null],
			'unknown server' => ['', '>=30 <=32', null],
		];
	}

	/**
	 * @dataProvider aboveMaximumCases
	 */
	public function testServerAboveMaximumIsTrueOnlyWhenTheMaximumAloneKeepsTheServerOut(string $server, string $spec, ?bool $expected): void {
		$this->assertSame($expected, AppStoreSource::serverAboveMaximum($server, $spec));
	}

	public function testAnIncompatibleReleaseCarriesServerTooNew(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('33.0.2.1');
		$normalize = new \ReflectionMethod(AppStoreSource::class, 'normalizeVersions');
		$source = (new \ReflectionClass(AppStoreSource::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(AppStoreSource::class, 'config'))->setValue($source, $config);

		$entries = $normalize->invoke($source, [
			['version' => '3.0.0', 'platformVersionSpec' => '>=34'],
			['version' => '2.0.0', 'platformVersionSpec' => '>=30 <=32'],
			['version' => '1.0.0', 'platformVersionSpec' => '>=30 <=33'],
		]);

		$this->assertSame(
			[['3.0.0', false, false], ['2.0.0', false, true], ['1.0.0', true, null]],
			array_map(static fn (array $entry): array => [$entry['version'], $entry['serverCompatible'], $entry['serverTooNew'] ?? null], $entries)
		);
	}
}
