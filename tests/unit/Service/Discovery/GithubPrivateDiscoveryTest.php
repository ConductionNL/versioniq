<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GithubPrivateDiscoveryTest extends TestCase {
	private function buildDiscovery(
		array $patsByUid = [],
		?string $currentUid = 'admin',
		?IResponse $response = null,
		array $allowlist = ['ConductionNL/*'],
	): GithubPrivateDiscovery {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findVisibleTo')->willReturnCallback(
			static fn (string $uid): array => $patsByUid[$uid] ?? []
		);

		// PatManager::useToken just calls the callback with a fake plaintext token.
		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(
			static fn (Pat $pat, callable $cb) => $cb('fake-plaintext-' . $pat->getId())
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'trusted_sources'
				? (string)json_encode($allowlist)
				: $default
		);
		$trustedSources = new TrustedSourceList($config);

		$userSession = $this->createMock(IUserSession::class);
		if ($currentUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($currentUid);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		$client = $this->createMock(IClient::class);
		if ($response !== null) {
			$client->method('get')->willReturn($response);
		}
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new GithubPrivateDiscovery(
			$mapper,
			$patManager,
			$trustedSources,
			$userSession,
			$clientService,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function makePat(int $id, string $owner, string $pattern): Pat {
		$pat = new Pat();
		$pat->setId($id);
		$pat->setOwnerUid($owner);
		$pat->setTargetPattern($pattern);

		return $pat;
	}

	public function testDisabledWithoutPats(): void {
		$discovery = $this->buildDiscovery(['admin' => []]);

		$this->assertFalse($discovery->isEnabled());
	}

	public function testEnabledWhenAdminHasPats(): void {
		$discovery = $this->buildDiscovery(['admin' => [$this->makePat(1, 'admin', 'ConductionNL/*')]]);

		$this->assertTrue($discovery->isEnabled());
	}

	public function testReturnsEmptyForUnauthenticatedSession(): void {
		$discovery = $this->buildDiscovery(currentUid: null);
		$result = $discovery->search('register');

		$this->assertSame([], $result->hits);
		$this->assertNotNull($result->error);
	}

	public function testHitsAnnotatedWithAllowlistStatus(): void {
		$body = json_encode([
			'items' => [
				[
					'full_name' => 'ConductionNL/openregister',
					'name' => 'openregister',
					'description' => 'data registers',
					'html_url' => 'https://github.com/ConductionNL/openregister',
				],
			],
		], JSON_THROW_ON_ERROR);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$discovery = $this->buildDiscovery(
			['admin' => [$this->makePat(1, 'admin', 'ConductionNL/*')]],
			response: $response,
		);

		$result = $discovery->search('register');

		$this->assertCount(1, $result->hits);
		$this->assertSame('openregister', $result->hits[0]->appId);
		$this->assertTrue($result->hits[0]->installable);
	}

	public function testNonAllowlistedRepoSurfacedAsNotInstallable(): void {
		$body = json_encode([
			'items' => [
				[
					'full_name' => 'OtherOrg/some-private',
					'name' => 'some-private',
					'description' => 'private app',
					'html_url' => 'https://github.com/OtherOrg/some-private',
				],
			],
		], JSON_THROW_ON_ERROR);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$discovery = $this->buildDiscovery(
			['admin' => [$this->makePat(1, 'admin', 'OtherOrg/some-private')]],
			response: $response,
			allowlist: ['ConductionNL/*'], // OtherOrg NOT allowlisted
		);

		$result = $discovery->search('private');

		$this->assertCount(1, $result->hits);
		$hit = $result->hits[0];
		$this->assertFalse($hit->installable);
		$this->assertNotNull($hit->installableReason);
	}

	public function testWildcardOnlyPatternSkipped(): void {
		// A PAT with target_pattern `*` cannot be safely scoped to a GitHub
		// search query — discovery should ignore it instead of doing a global search.
		$discovery = $this->buildDiscovery(['admin' => [$this->makePat(1, 'admin', '*')]]);
		$result = $discovery->search('register');

		$this->assertSame([], $result->hits);
	}
}
