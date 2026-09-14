<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Connection\ConnectionReportService;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A catalogue fetch tells the connection report whether the App Store
 * answered, and a cache hit, which makes no request, tells it nothing.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
 */
final class AppStoreSourceConnectionReportTest extends TestCase {
	private function source(IClient $client, ConnectionReportService $reports, ?IAppConfig $appConfig = null): AppStoreSource {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('32.0.0');

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn('en');

		return new AppStoreSource($clientService, $config, $appConfig ?? $this->createMock(IAppConfig::class), $l10nFactory, $reports);
	}

	private function answering(int $status, string $body): IClient {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		return $client;
	}

	private function reports(): ConnectionReportService&MockObject {
		return $this->createMock(ConnectionReportService::class);
	}

	public function testACatalogueAnswerReportsAnswered(): void {
		$reports = $this->reports();
		$reports->expects($this->once())->method('appStoreFetched')->with(true, '');

		$body = json_encode(['data' => [['id' => 'openregister', 'releases' => [['version' => '2.3.0']]]]], JSON_THROW_ON_ERROR);
		$result = $this->source($this->answering(200, $body), $reports)->listVersions('openregister', SourceBinding::appStore());

		self::assertSame('2.3.0', $result['versions'][0]['version']);
	}

	public function testAnOutageReportsTheLastFailureWithoutTheEndpoint(): void {
		$reports = $this->reports();
		$reports->expects($this->once())->method('appStoreFetched')->with(false, 'the last attempt got HTTP 502.');

		$result = $this->source($this->answering(502, ''), $reports)->listVersions('openregister', SourceBinding::appStore());

		// The listing answers exactly as before.
		self::assertSame([], $result['versions']);
		self::assertSame('App is not available in the Nextcloud App Store.', $result['error']);
	}

	public function testAnEmptyBodyIsNotAnAnswer(): void {
		$reports = $this->reports();
		$reports->expects($this->once())->method('appStoreFetched')->with(false, 'the last attempt got an empty body.');

		$this->source($this->answering(200, ''), $reports)->listVersions('openregister', SourceBinding::appStore());
	}

	public function testACacheHitReportsNothing(): void {
		$reports = $this->reports();
		$reports->expects($this->never())->method('appStoreFetched');

		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('get');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if (str_starts_with($key, 'appstore.payload_ts.')) {
					return (string)time();
				}
				if (str_starts_with($key, 'appstore.payload.')) {
					return json_encode(['id' => 'openregister', 'releases' => [['version' => '2.3.0']]], JSON_THROW_ON_ERROR);
				}

				return $default;
			},
		);

		$this->source($client, $reports, $appConfig)->listVersions('openregister', SourceBinding::appStore());
	}
}
