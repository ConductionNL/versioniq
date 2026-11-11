<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Discovery\GithubSearchDiscovery;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GithubSearchDiscoveryTest extends TestCase {
	private function buildDiscovery(bool $enabled, ?IResponse $response = null, array $allowlist = ['ConductionNL/*']): GithubSearchDiscovery {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match (true) {
				$app === Application::APP_ID && $key === 'discovery.github_search_enabled' => $enabled ? 'true' : 'false',
				$app === Application::APP_ID && $key === 'trusted_sources' => (string)json_encode($allowlist),
				default => $default,
			}
		);

		$trustedSources = new TrustedSourceList($config);

		$client = $this->createMock(IClient::class);
		if ($response !== null) {
			$client->method('get')->willReturn($response);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new GithubSearchDiscovery(
			$config,
			$trustedSources,
			$clientService,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function mockResponse(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	public function testDisabledByDefault(): void {
		$discovery = $this->buildDiscovery(enabled: false);

		$this->assertFalse($discovery->isEnabled());
		$this->assertSame([], $discovery->search('register')->hits);
	}

	public function testEnabledReturnsHitsWithAllowlistAnnotation(): void {
		$body = json_encode([
			'items' => [
				[
					'full_name' => 'ConductionNL/openregister',
					'name' => 'openregister',
					'description' => 'data registers',
					'html_url' => 'https://github.com/ConductionNL/openregister',
				],
				[
					'full_name' => 'OtherOrg/some-app',
					'name' => 'some-app',
					'description' => 'something else',
					'html_url' => 'https://github.com/OtherOrg/some-app',
				],
			],
		], JSON_THROW_ON_ERROR);

		$discovery = $this->buildDiscovery(enabled: true, response: $this->mockResponse(200, $body));
		$result = $discovery->search('register');

		$this->assertCount(2, $result->hits);
		$conduction = $result->hits[0];
		$this->assertSame('openregister', $conduction->appId);
		$this->assertTrue($conduction->installable);

		$other = $result->hits[1];
		$this->assertSame('some_app', $other->appId);
		$this->assertFalse($other->installable);
		$this->assertNotNull($other->installableReason);
		$this->assertStringContainsString('OtherOrg', $other->installableReason);
	}

	public function testRateLimitReturnsErrorEnvelope(): void {
		$discovery = $this->buildDiscovery(enabled: true, response: $this->mockResponse(403, ''));
		$result = $discovery->search('register');

		$this->assertSame([], $result->hits);
		$this->assertNotNull($result->error);
		$this->assertStringContainsString('rate limit', $result->error);
	}

	public function testEmptyQuerySkipsHttp(): void {
		$discovery = $this->buildDiscovery(enabled: true);
		$result = $discovery->search('');

		$this->assertSame([], $result->hits);
		$this->assertNull($result->error);
	}

	public function testMalformedJsonHandled(): void {
		$discovery = $this->buildDiscovery(enabled: true, response: $this->mockResponse(200, 'not json'));
		$result = $discovery->search('register');

		$this->assertSame([], $result->hits);
		$this->assertNotNull($result->error);
	}
}
