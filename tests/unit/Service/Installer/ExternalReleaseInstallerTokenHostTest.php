<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Exception;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Versioniq#481: a stored forge token was sent to whatever host a release's
 * asset or checksum URL named. The token belongs to one forge; a release that
 * links an asset elsewhere must not hand that host the token.
 *
 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.3
 */
final class ExternalReleaseInstallerTokenHostTest extends TestCase {
	/** @var list<array{url: string, authorization: ?string}> */
	private array $requests = [];
	private string $tempDir;

	protected function setUp(): void {
		parent::setUp();
		$this->tempDir = sys_get_temp_dir() . '/versioniq-token-host-' . bin2hex(random_bytes(4));
		mkdir($this->tempDir);
	}

	protected function tearDown(): void {
		foreach (glob($this->tempDir . '/*') ?: [] as $file) {
			@unlink($file);
		}
		@rmdir($this->tempDir);
		parent::tearDown();
	}

	/**
	 * Runs an install of `privateapp` 1.2.0 from GitHub with a stored token and
	 * records every request's URL and Authorization header. The archive request
	 * "succeeds" with junk bytes so the flow reaches the checksum fetch, which
	 * then fails; the install stops at extraction.
	 *
	 * @param array<string, string> $release The resolved release payload.
	 */
	private function install(array $release): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options): never {
			$this->requests[] = ['url' => $url, 'authorization' => $options['headers']['Authorization'] ?? null];
			if (isset($options['sink']) && is_string($options['sink'])) {
				file_put_contents($options['sink'], 'not an archive');
			}
			throw new Exception('stop: ' . $url);
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn($this->tempDir . '/archive.tar.gz');
		$tempManager->method('getTemporaryFolder')->willReturn($this->tempDir);

		$pat = new Pat();
		$pat->setId(7);
		$pat->setOwnerUid('admin');
		$pat->setTargetPattern('ConductionNL/*');
		$pat->setForge('github');
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findVisibleTo')->willReturn([$pat]);

		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(
			static fn (Pat $pat, callable $callback): mixed => $callback('forge-secret')
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$artifactCache = $this->createMock(ArtifactCache::class);
		$artifactCache->method('fetch')->willReturn(['content' => 'not an archive']);

		$installer = new ExternalReleaseInstallerService(
			$clientService,
			$tempManager,
			$this->createMock(IAppManager::class),
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(InstallFinalizer::class),
			$this->createMock(TrustedSourceList::class),
			$this->createMock(LoggerInterface::class),
			new PatResolver($mapper),
			$patManager,
			$userSession,
			$this->createMock(AuditLogger::class),
			$this->createMock(MigrationDiffer::class),
			$artifactCache,
			new ForgeRegistry($this->createMock(IAppConfig::class)),
		);

		try {
			$installer->installFromExternalRelease('privateapp', '1.2.0', $release, SourceBinding::github('ConductionNL', 'privateapp'));
		} catch (Throwable) {
			// Expected: the install stops after the requests under test.
		}
	}

	/**
	 * @return array<string, ?string> URL => Authorization header sent.
	 */
	private function authByUrl(): array {
		$result = [];
		foreach ($this->requests as $request) {
			$result[$request['url']] = $request['authorization'];
		}

		return $result;
	}

	public function testAnAssetOnAnotherHostIsFetchedWithoutTheToken(): void {
		$this->install([
			'download' => 'https://downloads.example.org/privateapp-1.2.0.tar.gz',
			'sha256Url' => 'https://downloads.example.org/privateapp-1.2.0.tar.gz.sha256',
		]);

		$auth = $this->authByUrl();
		$this->assertArrayHasKey('https://downloads.example.org/privateapp-1.2.0.tar.gz', $auth, 'the asset was never requested');
		$this->assertNull($auth['https://downloads.example.org/privateapp-1.2.0.tar.gz']);
		$this->assertArrayHasKey('https://downloads.example.org/privateapp-1.2.0.tar.gz.sha256', $auth, 'the checksum was never requested');
		$this->assertNull($auth['https://downloads.example.org/privateapp-1.2.0.tar.gz.sha256']);
	}

	public function testAChecksumOnAnotherHostIsFetchedWithoutTheToken(): void {
		$this->install([
			'download' => 'https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz',
			'sha256Url' => 'https://evil.example.net/privateapp.tar.gz.sha256',
		]);

		$auth = $this->authByUrl();
		$this->assertSame('Bearer forge-secret', $auth['https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz'] ?? 'not requested');
		$this->assertArrayHasKey('https://evil.example.net/privateapp.tar.gz.sha256', $auth, 'the checksum was never requested');
		$this->assertNull($auth['https://evil.example.net/privateapp.tar.gz.sha256']);
	}

	public function testALookalikeHostDoesNotGetTheToken(): void {
		$this->install([
			'download' => 'https://github.com.example.org/ConductionNL/privateapp/privateapp.tar.gz',
		]);

		$auth = $this->authByUrl();
		$this->assertArrayHasKey('https://github.com.example.org/ConductionNL/privateapp/privateapp.tar.gz', $auth, 'the asset was never requested');
		$this->assertNull($auth['https://github.com.example.org/ConductionNL/privateapp/privateapp.tar.gz']);
	}

	public function testAnAssetOnTheForgeKeepsTheToken(): void {
		$this->install([
			'download' => 'https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz',
			'sha256Url' => 'https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz.sha256',
		]);

		$auth = $this->authByUrl();
		$this->assertSame('Bearer forge-secret', $auth['https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz'] ?? 'not requested');
		$this->assertSame('Bearer forge-secret', $auth['https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz.sha256'] ?? 'not requested');
	}
}
