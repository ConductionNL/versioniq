<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\SourceBinding;
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

		return new AppStoreSource($clientService, $config, $appConfig, $l10nFactory);
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

		$source = new AppStoreSource($clientService, $config, $appConfig, $l10nFactory);

		$first = $source->listVersions('openregister', $this->binding());
		$second = $source->listVersions('openregister', $this->binding());

		$this->assertSame('2.3.0', $first['versions'][0]['version']);
		$this->assertSame($first['versions'], $second['versions'], 'cached listing must match the fetched one');
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

		$source = new AppStoreSource($clientService, $config, $appConfig, $l10nFactory);
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

		$source = new AppStoreSource($clientService, $config, $appConfig, $l10nFactory);
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

		$source = new AppStoreSource($clientService, $config, $appConfig, $l10nFactory);

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
}
