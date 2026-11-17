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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The server majors an App Store app's releases support, read from the cached
 * catalogue entry for the end-of-support signal.
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 */
#[CoversClass(AppStoreSource::class)]
final class AppStoreSupportedMajorsTest extends TestCase {
	use BuildsByParameterName;

	/** @var array<string, string> */
	private array $store = [];

	private IClient $client;

	/**
	 * @param list<array<string, mixed>> $releases
	 */
	private function source(array $releases): AppStoreSource {
		$body = json_encode(['data' => [['id' => 'legacyapp', 'releases' => $releases]]], JSON_THROW_ON_ERROR);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);
		$this->client = $this->createMock(IClient::class);
		$this->client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('31.0.0');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;

				return true;
			},
		);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default,
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

	public function testTheMajorsTheReleasesRangesCoverAreSupported(): void {
		$source = $this->source([
			['version' => '1.0.0', 'platformVersionSpec' => '>=26 <=27'],
			['version' => '1.1.0', 'platformVersionSpec' => '>=28.0.4 <=28'],
		]);
		$source->listVersions('legacyapp', SourceBinding::appStore());

		$this->assertSame([26, 27, 28], $source->supportedMajors('legacyapp', [25, 26, 27, 28, 29, 30]));
	}

	public function testAnAppWhoseReleasesStopAtAnOldMajorSupportsNoNewerOne(): void {
		$source = $this->source([['version' => '1.0.0', 'platformVersionSpec' => '>=26 <27']]);
		$source->listVersions('legacyapp', SourceBinding::appStore());

		$this->assertSame([26], $source->supportedMajors('legacyapp', [26, 27, 31, 32]));
		$this->assertSame([], $source->supportedMajors('legacyapp', [31, 32]));
	}

	public function testReleasesWithoutARangeAreUnknown(): void {
		$source = $this->source([['version' => '1.0.0'], ['version' => '1.1.0', 'platformVersionSpec' => '  ']]);
		$source->listVersions('legacyapp', SourceBinding::appStore());

		$this->assertNull($source->supportedMajors('legacyapp', [31, 32]));
	}

	public function testAnUncachedAppIsUnknownAndCostsNoRequest(): void {
		$source = $this->source([['version' => '1.0.0', 'platformVersionSpec' => '>=31']]);
		$this->client->expects($this->never())->method('get');

		$this->assertNull($source->supportedMajors('legacyapp', [31, 32]));
	}
}
