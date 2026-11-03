<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Background jobs (AdvisoryRefreshJob, AutoUpdateJob, PinReconcileJob) run with
 * no session user. A private repository answers an anonymous request with 404,
 * so without a token those jobs read a vulnerable private app as clean (#430).
 *
 * The resolver here is the REAL PatResolver over a mapper that holds a shared
 * token, so the test sees the actual lookup, not a stubbed answer.
 */
final class ForgeReleaseSourceBackgroundTokenTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<array<string, string>> */
	private array $sentHeaders = [];

	private function buildSource(): ForgeReleaseSource {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('[]');

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($response): IResponse {
			$this->sentHeaders[] = $options['headers'] ?? [];
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$shared = new Pat();
		$shared->setId(7);
		$shared->setOwnerUid('admin');
		$shared->setTargetPattern('ConductionNL/*');
		$shared->setForge('github');
		$shared->setSharedWithAdmins(true);

		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$shared]);
		$mapper->method('findVisibleTo')->willReturn([$shared]);

		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(
			static fn (Pat $pat, callable $callback): mixed => $callback('shared-secret')
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		return $this->buildByName(ForgeReleaseSource::class, [
			'clientService' => $clientService,
			'logger' => $this->createMock(LoggerInterface::class),
			'patResolver' => new PatResolver($mapper),
			'patManager' => $patManager,
			'userSession' => $userSession,
			'forgeRegistry' => new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)),
			'config' => $this->createMock(\OCP\IConfig::class),
		]);
	}

	public function testAdvisoriesUseTheSharedTokenWithoutASessionUser(): void {
		$this->buildSource()->listAdvisories('private-app', SourceBinding::github('ConductionNL', 'private-app'));

		$this->assertNotEmpty($this->sentHeaders);
		$this->assertSame('Bearer shared-secret', $this->sentHeaders[0]['Authorization'] ?? null);
	}

	public function testReleasesUseTheSharedTokenWithoutASessionUser(): void {
		$this->buildSource()->listVersions('private-app', SourceBinding::github('ConductionNL', 'private-app'));

		$this->assertNotEmpty($this->sentHeaders);
		$this->assertSame('Bearer shared-secret', $this->sentHeaders[0]['Authorization'] ?? null);
	}

	/**
	 * With a session user, the lookup runs for that user, so their own token
	 * wins over a token another admin shares.
	 */
	public function testASessionUserGetsTheirOwnToken(): void {
		$shared = new Pat();
		$shared->setOwnerUid('admin');
		$shared->setTargetPattern('ConductionNL/*');
		$shared->setForge('github');
		$shared->setSharedWithAdmins(true);
		$own = new Pat();
		$own->setOwnerUid('alice');
		$own->setTargetPattern('ConductionNL/*');
		$own->setForge('github');

		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$shared, $own]);
		$mapper->method('findVisibleTo')->willReturnCallback(
			static fn (string $uid): array => $uid === 'alice' ? [$shared, $own] : [$shared],
		);

		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(
			static fn (Pat $pat, callable $callback): mixed => $callback($pat->getOwnerUid() . '-secret')
		);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('[]');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($response): IResponse {
			$this->sentHeaders[] = $options['headers'] ?? [];
			return $response;
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$source = $this->buildByName(ForgeReleaseSource::class, [
			'clientService' => $clientService,
			'patResolver' => new PatResolver($mapper),
			'patManager' => $patManager,
			'userSession' => $userSession,
			'forgeRegistry' => new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)),
		]);
		$source->listVersions('private-app', SourceBinding::github('ConductionNL', 'private-app'));
		$source->listAdvisories('private-app', SourceBinding::github('ConductionNL', 'private-app'));

		$this->assertCount(2, $this->sentHeaders);
		$this->assertSame('Bearer alice-secret', $this->sentHeaders[0]['Authorization'] ?? null);
		$this->assertSame('Bearer alice-secret', $this->sentHeaders[1]['Authorization'] ?? null);
	}
}
