<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Discovery;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Discovery\AppStoreDiscovery;
use OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery;
use OCA\Versioniq\Service\Discovery\GithubSearchDiscovery;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit test for the identity and enabled state of the three discovery
 * providers, and for the App Store provider's failure result when the
 * catalogue cannot be fetched (nothing is cached then).
 *
 * @covers \OCA\Versioniq\Service\Discovery\AppStoreDiscovery
 * @covers \OCA\Versioniq\Service\Discovery\GithubPrivateDiscovery
 * @covers \OCA\Versioniq\Service\Discovery\GithubSearchDiscovery
 * @uses \OCA\Versioniq\Db\Pat
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryResult
 */
final class DiscoveryProviderStateTest extends TestCase {
	private function appStore(IClient $client, IAppConfig $config, LoggerInterface $logger): AppStoreDiscovery {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(2_000_000_000);

		return new AppStoreDiscovery($clientService, $config, $time, $logger);
	}

	private function uncachedConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0): int => $default);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$config->expects($this->never())->method('setValueString');
		$config->expects($this->never())->method('setValueInt');

		return $config;
	}

	private function githubPrivate(?IUser $user, PatMapper $mapper): GithubPrivateDiscovery {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new GithubPrivateDiscovery(
			$mapper,
			$this->createMock(PatManager::class),
			$this->createMock(TrustedSourceList::class),
			$session,
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function githubSearch(string $enabled): GithubSearchDiscovery {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'versioniq' && $key === 'discovery.github_search_enabled') ? $enabled : $default,
		);

		return new GithubSearchDiscovery(
			$config,
			$this->createMock(TrustedSourceList::class),
			$this->createMock(IClientService::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testAppStoreIdentityAndAlwaysEnabled(): void {
		$discovery = $this->appStore($this->createMock(IClient::class), $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class));

		$this->assertSame('appstore', $discovery->getId());
		$this->assertSame('Nextcloud App Store', $discovery->getLabel());
		$this->assertTrue($discovery->isEnabled());
	}

	public function testAppStoreFetchExceptionIsLoggedAndReportedAsFailure(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('connection refused'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('AppStoreDiscovery: catalog fetch failed', ['errorMessage' => 'connection refused']);

		$result = $this->appStore($client, $this->uncachedConfig(), $logger)->search('mail');

		$this->assertSame([], $result->hits);
		$this->assertSame('Could not fetch App Store catalog.', $result->error);
	}

	public function testAppStoreNon200ResponseIsReportedAsFailure(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(503);
		$response->method('getBody')->willReturn('[{"id":"mail","name":"Mail"}]');
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')->willReturn($response);

		$result = $this->appStore($client, $this->uncachedConfig(), $this->createMock(LoggerInterface::class))->search('mail');

		$this->assertSame([], $result->hits);
		$this->assertSame('Could not fetch App Store catalog.', $result->error);
	}

	public function testGithubPrivateIdentity(): void {
		$discovery = $this->githubPrivate(null, $this->createMock(PatMapper::class));

		$this->assertSame('github-private', $discovery->getId());
		$this->assertSame('GitHub (private, your PATs)', $discovery->getLabel());
	}

	public function testGithubPrivateIsDisabledWithoutASessionUser(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->never())->method('findVisibleTo');

		$this->assertFalse($this->githubPrivate(null, $mapper)->isEnabled());
	}

	public function testGithubPrivateIsEnabledOnlyWhenTheUserSeesAPat(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$none = $this->createMock(PatMapper::class);
		$none->expects($this->once())->method('findVisibleTo')->with('alice')->willReturn([]);
		$this->assertFalse($this->githubPrivate($user, $none)->isEnabled());

		$some = $this->createMock(PatMapper::class);
		$some->expects($this->once())->method('findVisibleTo')->with('alice')->willReturn([new Pat()]);
		$this->assertTrue($this->githubPrivate($user, $some)->isEnabled());
	}

	public function testGithubSearchIdentityAndOptIn(): void {
		$this->assertSame('github-search', $this->githubSearch('true')->getId());
		$this->assertSame('GitHub (public search)', $this->githubSearch('true')->getLabel());
		$this->assertTrue($this->githubSearch('true')->isEnabled());
		$this->assertFalse($this->githubSearch('false')->isEnabled());
		$this->assertFalse($this->githubSearch('1')->isEnabled());
		$this->assertFalse($this->githubSearch('')->isEnabled());
	}
}
