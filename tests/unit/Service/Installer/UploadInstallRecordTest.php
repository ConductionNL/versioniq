<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

require_once __DIR__ . '/../../Support/BuildsByParameterName.php';

require_once __DIR__ . '/InstallerGoldenSupport.php';

use Exception;
use OC\Files\FilenameValidator;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\LocalArchive;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\SignedInstall;
use OCA\Versioniq\Service\Installer\SignedRelease;
use OCA\Versioniq\Service\Installer\SignedReleaseRun;
use OCA\Versioniq\Service\Installer\TempArchive;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * install-manual-upload task 2.2: an install from an uploaded archive writes
 * its audit row under source `upload`, saying signed or unsigned and naming
 * the SHA-256, and puts the verified archive into the artifact cache under
 * the same source; the binding keeps no digest of it.
 *
 * @covers \OCA\Versioniq\Service\ExternalReleaseInstallerService
 * @covers \OCA\Versioniq\Service\SelectedReleaseInstallerService
 * @covers \OCA\Versioniq\Service\Installer\LocalArchive
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.2
 */
final class UploadInstallRecordTest extends TestCase {
	use BuildsByParameterName;

	private string $root = '';

	/** @var array<int, mixed>|null */
	private ?array $savedRoots = null;

	/** @var list<array{AuditSubject, AuditedVersions, string, ?string}> */
	private array $audits = [];

	/** @var list<array{string, string, string, array<string, mixed>, string}> */
	private array $cached = [];

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/versioniq-upload-record-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/tmp/extract', 0777, true);
		mkdir($this->root . '/apps', 0777, true);
		$this->savedRoots = \OC::$APPSROOTS;
		\OC::$APPSROOTS = [['path' => $this->root . '/apps', 'url' => '/apps', 'writable' => true]];
	}

	protected function tearDown(): void {
		InstallerGoldenSupport::resetServices();
		\OC::$APPSROOTS = $this->savedRoots;
		InstallerGoldenSupport::removeTree($this->root);
		parent::tearDown();
	}

	public function testAnUnsignedUploadIsAuditedAndCachedUnderTheUploadSource(): void {
		$archive = $this->archive();
		$binding = SourceBinding::github('acme', 'myapp');

		$result = $this->quietly(fn (): array => $this->externalInstaller()->installLocalArchive('myapp', '2.0.0', new LocalArchive($archive, hash_file('sha256', $archive)), $binding));

		self::assertSame('installed', $result['status']);
		self::assertSame($binding, $result['binding'], 'the binding records no digest of an upload');
		self::assertSame("<?php // uploaded\n", file_get_contents($this->root . '/apps/myapp/lib/Plugin.php'));

		self::assertCount(1, $this->audits);
		[$subject, $versions, $status, $message] = $this->audits[0];
		self::assertSame('myapp', $subject->appId);
		self::assertSame(AuditLogger::OPERATION_INSTALL, $subject->operation);
		self::assertSame('upload', $versions->sourceId);
		self::assertSame('2.0.0', $versions->toVersion);
		self::assertSame(AuditLogger::STATUS_SUCCESS, $status);
		self::assertSame('Installed from an uploaded archive, unsigned, SHA-256 ' . hash_file('sha256', $archive) . '.', $message);

		self::assertCount(1, $this->cached);
		[$appId, $version, , $meta, $bytesSha] = $this->cached[0];
		self::assertSame(['myapp', '2.0.0'], [$appId, $version]);
		self::assertSame('upload', $meta['sourceId']);
		self::assertSame(hash_file('sha256', $archive), $meta['sha256']);
		self::assertSame(hash_file('sha256', $archive), $bytesSha, 'the cache gets the bytes that were installed');
	}

	public function testAnUploadWhoseShaChangedIsAuditedAsAFailureAndNotCached(): void {
		$archive = $this->archive();

		try {
			$this->quietly(fn (): array => $this->externalInstaller()->installLocalArchive('myapp', '2.0.0', new LocalArchive($archive, str_repeat('0', 64)), SourceBinding::appStore()));
			self::fail('The install should have been refused.');
		} catch (Exception $error) {
			self::assertStringContainsString('SHA-256 mismatch', $error->getMessage());
		}

		self::assertFileDoesNotExist($this->root . '/apps/myapp');
		self::assertSame([], $this->cached);
		self::assertCount(1, $this->audits);
		self::assertSame('upload', $this->audits[0][1]->sourceId);
		self::assertSame(AuditLogger::STATUS_FAILURE, $this->audits[0][2]);
		self::assertStringEndsWith('Installed from an uploaded archive, unsigned, SHA-256 ' . str_repeat('0', 64) . '.', (string)$this->audits[0][3]);
	}

	public function testASignedUploadIsAuditedAndCachedUnderTheUploadSource(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		self::assertNotFalse($key);
		$csr = openssl_csr_new(['commonName' => 'myapp'], $key);
		self::assertNotFalse($csr);
		$cert = openssl_csr_sign($csr, null, $key, 1);
		self::assertNotFalse($cert);
		openssl_x509_export($cert, $pem);
		$bytes = 'signed archive bytes';
		openssl_sign($bytes, $signature, $key, OPENSSL_ALGO_SHA512);
		file_put_contents($this->root . '/tmp/signed.tar.gz', $bytes);

		$audit = $this->auditLogger();
		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$audit,
			$this->userSession(),
			$this->createMock(MigrationDiffer::class),
			$this->artifactCache(),
		);
		$local = new LocalArchive($this->root . '/tmp/signed.tar.gz', hash('sha256', $bytes), ['version' => '2.0.0']);
		(new ReflectionProperty($installer, 'localArchive'))->setValue($installer, $local);

		$release = new SignedRelease('https://dl.example/myapp.tar.gz', base64_encode($signature), (string)$pem, '2.0.0');
		$run = new SignedReleaseRun('myapp', $release, new TempArchive($this->root . '/tmp/signed.tar.gz', $this->root . '/tmp/extract'));
		(new ReflectionMethod($installer, 'verifySignature'))->invoke($installer, $run);
		$install = new SignedInstall('myapp', ['version' => '2.0.0'], '1.0.0', 'yes');
		(new ReflectionMethod($installer, 'persistArchive'))->invoke($installer, $install);
		(new ReflectionMethod($installer, 'recordInstallAudit'))->invoke($installer, $install, [AuditLogger::STATUS_SUCCESS, null]);

		self::assertCount(1, $this->cached);
		self::assertSame('upload', $this->cached[0][3]['sourceId']);
		self::assertSame(hash('sha256', $bytes), $this->cached[0][3]['sha256']);
		self::assertSame(base64_encode($signature), $this->cached[0][3]['signature'], 'a signed upload keeps its store signature for a later re-verification');
		self::assertCount(1, $this->audits);
		self::assertSame('upload', $this->audits[0][1]->sourceId);
		self::assertSame('Installed from an uploaded archive, signed by the App Store, SHA-256 ' . hash('sha256', $bytes) . '.', $this->audits[0][3]);
	}

	/**
	 * Runs an install with PHP warnings collected, as the installer golden tests
	 * do: copying into the fresh app folder re-creates it (`mkdir(): File
	 * exists`), which the installer tolerates.
	 *
	 * @param callable(): array<string, mixed> $install
	 * @return array<string, mixed>
	 */
	private function quietly(callable $install): array {
		set_error_handler(static fn (): bool => true);
		try {
			return $install();
		} finally {
			restore_error_handler();
		}
	}

	private function archive(): string {
		$path = $this->root . '/upload.tar.gz';
		InstallerGoldenSupport::tarGz($path, [
			'myapp/appinfo/info.xml' => "<?xml version=\"1.0\"?>\n<info><id>myapp</id><version>2.0.0</version><name>My App</name></info>\n",
			'myapp/lib/Plugin.php' => "<?php // uploaded\n",
		]);

		return $path;
	}

	private function externalInstaller(): ExternalReleaseInstallerService {
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('en');
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$version = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(ServerVersion::class, 'versionString'))->setValue($version, '33.0.1');
		InstallerGoldenSupport::useServices([
			IFactory::class => $factory,
			ServerVersion::class => $version,
			FilenameValidator::class => $this->createMock(FilenameValidator::class),
		]);

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturn($this->root . '/tmp/archive.tar.gz');
		$tempManager->method('getTemporaryFolder')->willReturn($this->root . '/tmp/extract');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('');
		$appManager->method('getAppPath')->willThrowException(new AppPathNotFoundException('not installed'));
		$appManager->method('getAppInfoByPath')->willReturn(['id' => 'myapp', 'version' => '2.0.0', 'name' => 'My App']);
		$appManager->method('isAppCompatible')->willReturn(true);
		$finalizer = $this->createMock(InstallFinalizer::class);
		$finalizer->method('finalize')->willReturnCallback(static function (string $path, array $info, string $enabled, mixed $l, string $sourceId): string {
			self::assertSame('upload', $sourceId);

			return 'myapp';
		});
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->expects($this->never())->method('assertBindingAllowed');
		$client = $this->createMock(IClientService::class);
		$client->expects($this->never())->method('newClient');

		return $this->buildByName(ExternalReleaseInstallerService::class, [
			'clientService' => $client,
			'tempManager' => $tempManager,
			'appManager' => $appManager,
			'config' => $this->createMock(IConfig::class),
			'appConfig' => $this->createMock(IAppConfig::class),
			'finalizer' => $finalizer,
			'trustedSources' => $trusted,
			'userSession' => $this->userSession(),
			'auditLogger' => $this->auditLogger(),
			'migrationDiffer' => $this->createMock(MigrationDiffer::class),
			'artifactCache' => $this->artifactCache(),
			'forgeRegistry' => new ForgeRegistry($this->createMock(IAppConfig::class)),
		]);
	}

	private function userSession(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	private function auditLogger(): AuditLogger {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audits[] = [$subject, $versions, $status, $message];
		});

		return $audit;
	}

	private function artifactCache(): ArtifactCache {
		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('store')->willReturnCallback(function (string $appId, string $version, string $path, array $meta): void {
			$this->cached[] = [$appId, $version, $path, $meta, (string)hash_file('sha256', $path)];
		});

		return $cache;
	}
}
