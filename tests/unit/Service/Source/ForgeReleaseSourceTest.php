<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use Exception;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ForgeReleaseSourceTest extends TestCase {
	private function buildSource(IClient $client): ForgeReleaseSource {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);

		// PatResolver returns null (no PAT) so we exercise the unauthenticated public path.
		$patResolver = $this->createMock(PatResolver::class);
		$patResolver->method('findFor')->willReturn(null);

		$patManager = $this->createMock(PatManager::class);

		// Default: no logged-in user → no PAT lookup at all.
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return new ForgeReleaseSource($clientService, $logger, $patResolver, $patManager, $userSession, new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)), $this->createMock(\OCP\IConfig::class));
	}

	private function mockResponse(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	public function testListVersionsReturnsSortedTags(): void {
		$body = json_encode([
			['tag_name' => 'v2.5.0'],
			['tag_name' => 'v2.4.0'],
			['tag_name' => '2.6.0'],
			['tag_name' => 'v2.5.0'], // duplicate
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNull($result['error']);
		$this->assertCount(3, $result['versions']);
		$this->assertSame('2.6.0', $result['versions'][0]['version']);
		$this->assertSame('2.5.0', $result['versions'][1]['version']);
		$this->assertSame('2.4.0', $result['versions'][2]['version']);
	}

	public function testListVersionsHandles404Gracefully(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(404, ''));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertSame('GitHub repository not found.', $result['error']);
	}

	public function testListVersionsHandlesRateLimit(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(403, ''));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('rate limit', $result['error']);
	}

	public function testListVersionsHandlesNetworkException(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new Exception('Could not resolve host'));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('Could not reach', $result['error']);
	}

	public function testListVersionsHandlesMalformedJson(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, 'not json'));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('malformed JSON', $result['error']);
	}

	public function testResolveReleaseFindsMatchingTagAndAsset(): void {
		$body = json_encode([
			[
				'tag_name' => 'v2.5.0',
				'assets' => [
					[
						'name' => 'openregister-2.5.0.tar.gz',
						'browser_download_url' => 'https://example.invalid/openregister-2.5.0.tar.gz',
					],
					[
						'name' => 'openregister-2.5.0.tar.gz.sha256',
						'browser_download_url' => 'https://example.invalid/openregister-2.5.0.tar.gz.sha256',
					],
				],
			],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'openregister',
			'2.5.0',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNotNull($release);
		$this->assertSame('2.5.0', $release['version']);
		$this->assertSame('https://example.invalid/openregister-2.5.0.tar.gz', $release['download']);
		$this->assertSame('https://example.invalid/openregister-2.5.0.tar.gz.sha256', $release['sha256Url']);
	}

	public function testResolveReleaseFailsWhenMultipleMatchingAssets(): void {
		$body = json_encode([
			[
				'tag_name' => 'v2.5.0',
				'assets' => [
					[
						'name' => 'openregister-2.5.0.tar.gz',
						'browser_download_url' => 'https://example.invalid/a.tar.gz',
					],
					[
						'name' => 'openregister-2.5.0-debug.tar.gz',
						'browser_download_url' => 'https://example.invalid/b.tar.gz',
					],
				],
			],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'openregister',
			'2.5.0',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNotNull($release);
		$this->assertArrayHasKey('error', $release);
		$this->assertStringContainsString('Multiple matching assets', $release['error']);
	}

	public function testResolveReleaseReturnsNullForUnknownVersion(): void {
		$body = json_encode([['tag_name' => 'v2.4.0', 'assets' => []]], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'openregister',
			'2.5.0',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNull($release);
	}

	public function testListVersionsForCodebergUsesForgejoEndpoint(): void {
		$body = json_encode([
			['tag_name' => 'v1.2.0'],
			['tag_name' => '1.1.0'],
		], JSON_THROW_ON_ERROR);

		$capturedUrl = null;
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use (&$capturedUrl, $body): IResponse {
			$capturedUrl = $url;

			return $this->mockResponse(200, $body);
		});

		$result = $this->buildSource($client)->listVersions(
			'pipelinq',
			SourceBinding::codeberg('Conduction', 'pipelinq')
		);

		// The driver targets Forgejo's API base, not GitHub's.
		$this->assertIsString($capturedUrl);
		$this->assertStringContainsString('https://codeberg.org/api/v1/repos/Conduction/pipelinq/releases', (string)$capturedUrl);
		$this->assertNull($result['error']);
		$this->assertSame('1.2.0', $result['versions'][0]['version']);
	}

	public function testCodebergRepoNotFoundUsesCodebergWording(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(404, ''));

		$result = $this->buildSource($client)->listVersions(
			'pipelinq',
			SourceBinding::codeberg('Conduction', 'pipelinq')
		);

		$this->assertSame([], $result['versions']);
		$this->assertSame('Codeberg repository not found.', $result['error']);
	}

	/**
	 * @spec openspec/specs/changelog-visibility/spec.md
	 */
	public function testListVersionsMapsReleaseBodyAsChangelog(): void {
		$body = json_encode([
			['tag_name' => 'v2.3.0', 'body' => 'Fixes LDAP sync'],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame('Fixes LDAP sync', $result['versions'][0]['changelog']);
	}

	public function testListVersionsMissingBodyYieldsNullChangelog(): void {
		$body = json_encode([
			['tag_name' => 'v2.3.0'],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNull($result['error']);
		$this->assertNull($result['versions'][0]['changelog']);
	}

	public function testListVersionsBlankBodyNormalizesToNull(): void {
		$body = json_encode([
			['tag_name' => 'v2.3.0', 'body' => "  \n "],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNull($result['versions'][0]['changelog']);
	}

	public function testListVersionsMalformedBodyIsFailSoftNull(): void {
		$body = json_encode([
			// Malformed: body should be a string, not a nested object.
			['tag_name' => 'v2.3.0', 'body' => ['nested' => 'value']],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		// The throwing mapper must not fail the whole listing.
		$this->assertNull($result['error']);
		$this->assertSame('2.3.0', $result['versions'][0]['version']);
		$this->assertNull($result['versions'][0]['changelog']);
	}
}
