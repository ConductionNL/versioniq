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
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AutoUpdateJob installs with no session user. The download of a private
 * repository's release asset must still carry the admins' shared token, or
 * GitHub answers 404 and the automatic update never lands (#430).
 */
final class ExternalReleaseInstallerBackgroundTokenTest extends TestCase {
	public function testDownloadCarriesTheSharedTokenWithoutASessionUser(): void {
		$sentHeaders = [];
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use (&$sentHeaders): never {
			$sentHeaders[] = $options['headers'] ?? [];
			// Stop the install right after the download request: only the
			// request's credentials are under test here.
			throw new Exception('stop after download request');
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$tempDir = sys_get_temp_dir() . '/versioniq-bg-token-' . bin2hex(random_bytes(4));
		mkdir($tempDir);
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn($tempDir . '/archive.tar.gz');
		$tempManager->method('getTemporaryFolder')->willReturn($tempDir);

		$shared = new Pat();
		$shared->setId(3);
		$shared->setOwnerUid('admin');
		$shared->setTargetPattern('ConductionNL/*');
		$shared->setForge('github');
		$shared->setSharedWithAdmins(true);
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$shared]);

		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(
			static fn (Pat $pat, callable $callback): mixed => $callback('shared-secret')
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$artifactCache = $this->createMock(ArtifactCache::class);
		$artifactCache->method('fetch')->willReturn(null);

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
		);

		try {
			$installer->installFromExternalRelease(
				'privateapp',
				'1.2.0',
				['download' => 'https://github.com/ConductionNL/privateapp/releases/download/v1.2.0/privateapp.tar.gz'],
				SourceBinding::github('ConductionNL', 'privateapp'),
			);
		} catch (Throwable) {
			// Expected: the client stops the flow after the download request.
		} finally {
			@unlink($tempDir . '/archive.tar.gz');
			@rmdir($tempDir);
		}

		$this->assertNotEmpty($sentHeaders, 'the installer never requested the release asset');
		$this->assertSame('Bearer shared-secret', $sentHeaders[0]['Authorization'] ?? null);
	}
}
