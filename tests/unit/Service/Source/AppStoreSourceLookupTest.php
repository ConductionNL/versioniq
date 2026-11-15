<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use Exception;
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
 * Unit test for the App Store source's kind, its release lookup for an
 * install and the error results of its version and advisory listings: an
 * unreachable store, an app the store does not list, and payloads of an
 * unexpected shape.
 *
 * @covers \OCA\Versioniq\Service\Source\AppStoreSource
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryClauses
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @uses \OCA\Versioniq\Service\Connection\ReportedHttp
 * @uses \OCA\Versioniq\Service\Source\CatalogueQuery
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class AppStoreSourceLookupTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * A source whose catalogue answers the given apps, or whose HTTP client
	 * throws when $failure is set. Nothing is cached beforehand.
	 *
	 * @param list<array<string, mixed>> $apps
	 */
	private function source(array $apps, ?Exception $failure = null): AppStoreSource {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['data' => $apps], JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		if ($failure !== null) {
			$client->method('get')->willThrowException($failure);
		} else {
			$client->method('get')->willReturn($response);
		}
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'version' ? '30.0.4.1' : $default,
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default,
		);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		return $this->buildByName(AppStoreSource::class, [
			'clientService' => $clientService,
			'config' => $config,
			'appConfig' => $appConfig,
			'l10nFactory' => $l10nFactory,
		]);
	}

	public function testKindsNameTheAppStoreAndTheSignedInstaller(): void {
		$source = $this->source([]);

		$this->assertSame(SourceBinding::KIND_APPSTORE, $source->getKind());
		$this->assertSame(AppStoreSource::INSTALLER_SIGNED, $source->getInstallerKind());
	}

	public function testResolveReleaseMergesTheAppCertificateIntoTheRequestedRelease(): void {
		$source = $this->source([[
			'id' => 'mail',
			'certificate' => '-----BEGIN CERTIFICATE-----x',
			'releases' => [
				'not a release',
				['version' => '4.0.0', 'download' => 'https://example/mail-4.0.0.tar.gz', 'signature' => 'sig4'],
				['version' => '3.9.0', 'download' => 'https://example/mail-3.9.0.tar.gz', 'signature' => 'sig3'],
			],
		]]);

		$this->assertSame([
			'version' => '3.9.0',
			'download' => 'https://example/mail-3.9.0.tar.gz',
			'signature' => 'sig3',
			'certificate' => '-----BEGIN CERTIFICATE-----x',
			'kind' => 'appstore',
		], $source->resolveRelease('mail', '3.9.0', SourceBinding::appStore()));
	}

	public function testResolveReleaseWithoutACertificateCarriesNull(): void {
		$source = $this->source([['id' => 'mail', 'releases' => [['version' => '4.0.0']]]]);

		$release = $source->resolveRelease('mail', '4.0.0', SourceBinding::appStore());

		$this->assertNotNull($release);
		$this->assertNull($release['certificate']);
		$this->assertSame('appstore', $release['kind']);
	}

	public function testResolveReleaseIsNullForAnUnknownVersionOrApp(): void {
		$source = $this->source([['id' => 'mail', 'releases' => [['version' => '4.0.0']]]]);

		$this->assertNull($source->resolveRelease('mail', '9.9.9', SourceBinding::appStore()));
		$this->assertNull($source->resolveRelease('unknown', '4.0.0', SourceBinding::appStore()));
	}

	public function testResolveReleaseIsNullWhenReleasesAreNotAList(): void {
		$source = $this->source([['id' => 'mail', 'releases' => 'broken']]);

		$this->assertNull($source->resolveRelease('mail', '4.0.0', SourceBinding::appStore()));
	}

	public function testListingAnAppTheStoreDoesNotCarryIsAnError(): void {
		$source = $this->source([['id' => 'other', 'releases' => []]]);

		$this->assertSame(
			['versions' => [], 'error' => 'App is not available in the Nextcloud App Store.'],
			$source->listVersions('mail', SourceBinding::appStore()),
		);
	}

	public function testListingReleasesOfAnUnexpectedShapeIsAnError(): void {
		$source = $this->source([['id' => 'mail', 'releases' => 'broken']]);

		$this->assertSame(
			['versions' => [], 'error' => 'App store returned an unexpected payload shape.'],
			$source->listVersions('mail', SourceBinding::appStore()),
		);
	}

	public function testAdvisoriesOfAnAppTheStoreDoesNotCarryAreAnEmptyCleanResult(): void {
		$source = $this->source([['id' => 'other']]);

		$this->assertSame(['advisories' => [], 'error' => null], $source->listAdvisories('mail', SourceBinding::appStore()));
	}

	public function testAnAppWithoutAdvisoryDataHasNoAdvisories(): void {
		$source = $this->source([['id' => 'mail', 'releases' => [], 'securityAdvisories' => 'n/a']]);

		$this->assertSame(['advisories' => [], 'error' => null], $source->listAdvisories('mail', SourceBinding::appStore()));
	}

	public function testSnakeCaseAdvisoriesAreRead(): void {
		$source = $this->source([['id' => 'mail', 'security_advisories' => [['id' => 'NC-9', 'summary' => 'Snake', 'severity' => 'low']]]]);

		$result = $source->listAdvisories('mail', SourceBinding::appStore());

		$this->assertNull($result['error']);
		$this->assertSame(['NC-9'], array_column($result['advisories'], 'id'));
		$this->assertSame(['low'], array_column($result['advisories'], 'severity'));
	}

	/**
	 * A request that throws is not an exception for the caller: with nothing
	 * cached, the store reads as not listing the app, so versions report
	 * that and advisories stay a clean, empty result.
	 */
	public function testAnUnreachableStoreWithNothingCachedReadsAsAnUnlistedApp(): void {
		$source = $this->source([], new Exception('Connection timed out'));

		$this->assertSame(
			['versions' => [], 'error' => 'App is not available in the Nextcloud App Store.'],
			$source->listVersions('mail', SourceBinding::appStore()),
		);
		$this->assertSame(['advisories' => [], 'error' => null], $source->listAdvisories('mail', SourceBinding::appStore()));
		$this->assertNull($source->resolveRelease('mail', '4.0.0', SourceBinding::appStore()));
	}
}
