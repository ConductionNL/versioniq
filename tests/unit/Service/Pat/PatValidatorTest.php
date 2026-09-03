<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use Exception;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PatValidatorTest extends TestCase {
	/**
	 * @param array<string, list<string>|string> $headers
	 */
	private function buildValidator(int $status, array $headers = [], string $body = '{}'): PatValidator {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeaders')->willReturn($headers);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);

		return new PatValidator($clientService, $logger, new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)), $this->createMock(\OCP\IConfig::class));
	}

	private function buildValidatorWithException(Exception $error): PatValidator {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException($error);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new PatValidator($clientService, $this->createMock(LoggerInterface::class), new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)), $this->createMock(\OCP\IConfig::class));
	}

	public function testDetectKindClassic(): void {
		$validator = $this->buildValidator(200);

		$this->assertSame(Pat::KIND_CLASSIC, $validator->detectKind('ghp_1234567890abcdef1234567890abcdef12345678'));
	}

	public function testDetectKindFineGrained(): void {
		$validator = $this->buildValidator(200);

		$this->assertSame(Pat::KIND_FINE_GRAINED, $validator->detectKind('github_pat_11AAAAA_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'));
	}

	public function testClassicWithRepoScopeAccepted(): void {
		$validator = $this->buildValidator(200, ['X-OAuth-Scopes' => ['repo']]);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertTrue($result->ok);
		$this->assertSame(['repo'], $result->scopes);
		$this->assertSame([], $result->warnings);
	}

	public function testClassicWithBroaderScopeRejected(): void {
		$validator = $this->buildValidator(200, ['X-OAuth-Scopes' => 'repo, write:packages, admin:org']);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertFalse($result->ok);
		$this->assertNotNull($result->error);
		$this->assertStringContainsString('write:packages', $result->error);
		$this->assertStringContainsString('admin:org', $result->error);
	}

	public function testUnknownForgeRejectedInBandNotThrown(): void {
		// An unknown forge must fail closed as a rejected ValidationResult (-> 400),
		// not throw InvalidArgumentException (which would surface as HTTP 500).
		$validator = $this->buildValidator(200);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef', 'gitlab');

		$this->assertFalse($result->ok);
		$this->assertNotNull($result->error);
		$this->assertStringContainsString('Unknown forge', $result->error);
	}

	public function testInvalidTokenRejectedOn401(): void {
		$validator = $this->buildValidator(401);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertFalse($result->ok);
		$this->assertSame('Token is invalid or revoked.', $result->error);
	}

	public function testRateLimitedRejectedOn403(): void {
		$validator = $this->buildValidator(403);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertFalse($result->ok);
		$this->assertNotNull($result->error);
		$this->assertStringContainsString('rate limit', $result->error);
	}

	public function testFineGrainedAcceptedWithUnverifiableScopeWarning(): void {
		$validator = $this->buildValidator(200, ['X-OAuth-Scopes' => '']);

		$result = $validator->validate('github_pat_11ABCD1234');

		$this->assertTrue($result->ok);
		$this->assertSame([], $result->scopes);
		$this->assertCount(1, $result->warnings);
		$this->assertStringContainsString('unverifiable_scope', $result->warnings[0]);
	}

	public function testNetworkErrorRejected(): void {
		$validator = $this->buildValidatorWithException(new Exception('Could not resolve host'));

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertFalse($result->ok);
		$this->assertNotNull($result->error);
		$this->assertStringContainsString('reach', $result->error);
	}

	public function testExpirationHeaderParsed(): void {
		$validator = $this->buildValidator(200, [
			'X-OAuth-Scopes' => ['repo'],
			'github-authentication-token-expiration' => '2026-08-15 12:00:00 UTC',
		]);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertTrue($result->ok);
		$this->assertNotNull($result->expiresAt);
		$this->assertStringContainsString('2026-08-15 12:00:00', $result->expiresAt);
	}

	public function testHeaderLookupIsCaseInsensitive(): void {
		// GitHub returns the scopes header as `X-OAuth-Scopes` but downstream proxies / mocks may lower-case it.
		$validator = $this->buildValidator(200, ['x-oauth-scopes' => 'public_repo']);

		$result = $validator->validate('ghp_anyclassic1234567890abcdef1234567890abcdef');

		$this->assertTrue($result->ok);
		$this->assertSame(['public_repo'], $result->scopes);
	}

	public function testCodebergTokenAcceptedWithUnverifiableScope(): void {
		// Forgejo exposes no scope header, so a valid (HTTP 200) token is accepted best-effort.
		$validator = $this->buildValidator(200);

		$result = $validator->validate('cb-opaque-token-value', 'codeberg');

		$this->assertTrue($result->ok);
		$this->assertSame([], $result->scopes);
		$this->assertNull($result->expiresAt);
		$this->assertNotEmpty($result->warnings);
		$this->assertStringContainsString('unverifiable_scope', $result->warnings[0]);
	}

	public function testCodebergInvalidTokenRejected(): void {
		$validator = $this->buildValidator(401);

		$result = $validator->validate('cb-bad-token', 'codeberg');

		$this->assertFalse($result->ok);
		$this->assertSame('Token is invalid or revoked.', $result->error);
	}
}
