<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/InstallArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

require_once __DIR__ . '/Installer/InstallerGoldenSupport.php';

use Exception;
use OC\Files\FilenameValidator;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Checks\CheckRunner;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Service\Installer\InstallerGoldenSupport;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\ServerVersion;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A dry run must change nothing (#427). It used to switch the whole instance
 * into maintenance mode while the package downloaded, and, for App Store apps,
 * to rename the live app folder aside and back.
 */
final class InstallerDryRunSideEffectsTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<array{0: string, 1: mixed}> */
	private array $systemWrites = [];

	private function service(): InstallerService {
		$config = $this->createMock(IConfig::class);
		// Maintenance is OFF, so a run that takes it would have to write it.
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('setSystemValue')->willReturnCallback(function (string $key, mixed $value): void {
			$this->systemWrites[] = [$key, $value];
		});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$appManager->method('getAppVersion')->willReturn('2.0.0');
		$appManager->method('getAppPath')->willReturn('/writable/someapp');

		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '1.0.0']);
		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($source);

		$signed = $this->createMock(SelectedReleaseInstallerService::class);
		$signed->method('installFromSelectedRelease')->willReturn(['status' => 'dry-run', 'dryRun' => true]);
		$signed->method('getDebugLog')->willReturn([]);

		$environment = $this->createMock(EnvironmentCheck::class);
		$environment->method('isDestinationWritable')->willReturn(true);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-27T00:00:00+00:00'));

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		return $this->buildByName(InstallerService::class, [
			'appManager' => $appManager,
			'config' => $config,
			'appConfig' => $this->createMock(IAppConfig::class),
			'sourceRegistry' => $registry,
			'bindingStore' => $this->createMock(SourceBindingStore::class),
			'trustedSources' => $this->createMock(TrustedSourceList::class),
			'signedInstaller' => $signed,
			'externalInstaller' => $this->createMock(ExternalReleaseInstallerService::class),
			'failureClassifier' => new FailureClassifier($factory),
			'environmentCheck' => $environment,
			'pinStore' => $this->createMock(PinStore::class),
			'userSession' => $this->createMock(IUserSession::class),
			'timeFactory' => $timeFactory,
			'lkgStore' => $this->createMock(LkgStore::class),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'eventDispatcher' => $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		]);
	}

	public function testDryRunNeverTakesMaintenanceMode(): void {
		InstallArgs::call($this->service(), 'someapp', '1.0.0', false, dryRun: true);

		$maintenanceWrites = array_filter($this->systemWrites, static fn (array $w): bool => $w[0] === 'maintenance');
		self::assertSame([], array_values($maintenanceWrites), 'a dry run switched maintenance mode');
	}

	public function testRealRunStillTakesAndReleasesMaintenanceMode(): void {
		InstallArgs::call($this->service(), 'someapp', '1.0.0', false, allowDowngrade: true, dryRun: false);

		self::assertSame([['maintenance', true], ['maintenance', false]], $this->systemWrites);
	}

	/**
	 * The parent directory's mtime moves on any rename inside it, so it tells
	 * whether the live folder was moved aside, even when it was moved back.
	 */
	public function testDryRunNeverMovesTheLiveAppFolder(): void {
		$root = sys_get_temp_dir() . '/versioniq-dryrun-' . bin2hex(random_bytes(4));
		$live = $root . '/someapp';
		mkdir($live, 0777, true);
		file_put_contents($live . '/marker', 'live');
		touch($root, 1_000_000_000);
		clearstatcache();

		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(ArtifactCache::class),
		);
		$backup = new ReflectionMethod($installer, 'backupExistingFolder');

		try {
			$result = $backup->invoke($installer, $live, true, true);
			clearstatcache();

			self::assertNull($result);
			self::assertSame(1_000_000_000, filemtime($root), 'the live app folder was renamed during a dry run');
			self::assertDirectoryDoesNotExist($live . '.appversion-backup');
			self::assertSame('live', file_get_contents($live . '/marker'));
		} finally {
			@unlink($live . '/marker');
			@rmdir($live);
			@rmdir($live . '.appversion-backup');
			@rmdir($root);
		}
	}

	/**
	 * A dry run of an App Store package validates its info.xml like a real
	 * install: compatibility and dependencies. Each case is
	 * [compatible, dependency error or null, expected error or null].
	 *
	 * @return array<string, array{0: bool, 1: ?string, 2: ?string}>
	 */
	public static function signedDryRunCases(): array {
		return [
			'not compatible with this server' => [false, null, 'App "Some App" cannot be installed because it is not compatible with this version of the server.'],
			'a PHP extension is missing' => [true, 'App "Some App" cannot be installed because the following dependencies are not fulfilled: The library intl is not available.', 'App "Some App" cannot be installed because the following dependencies are not fulfilled: The library intl is not available.'],
			'everything is met' => [true, null, null],
		];
	}

	/**
	 * @dataProvider signedDryRunCases
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-a-dry-run-validates-the-package-like-a-real-install
	 */
	public function testASignedDryRunValidatesTheUnpackedPackageAndChangesNothing(bool $compatible, ?string $dependencyError, ?string $expectedError): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Runs on the unit-only bootstrap, whose archive and X509 classes and server container it controls.');
		}

		$run = $this->signedDryRun($compatible, $dependencyError);

		self::assertSame($expectedError, $run['error']);
		self::assertSame([], $run['writes'], 'a dry run wrote config or switched maintenance mode');
		self::assertSame(['someapp' => null, 'someapp/old.txt' => 'old'], $run['apps'], 'a dry run changed the app folder');
		// Validated on the unpacked package, never on the live folder.
		self::assertCount(1, $run['infoPaths']);
		self::assertStringStartsWith($run['root'] . '/tmp/extract/', $run['infoPaths'][0]);
		if ($expectedError === null) {
			self::assertSame('dry-run', $run['result']['status'] ?? null);
		}
	}

	/**
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function testASignedDryRunRunsTheChecksOnTheUnpackedPackage(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Runs on the unit-only bootstrap, whose archive and X509 classes and server container it controls.');
		}
		$checks = [['name' => 'Lint', 'kind' => 'command', 'outcome' => 'failed', 'passed' => false, 'detail' => 'Exit code 1.']];
		$runner = $this->createMock(CheckRunner::class);
		$runner->expects(self::once())
			->method('run')
			->with('someapp', '1.0.0', '2.0.0', self::callback(static fn (string $path): bool => is_file($path . '/appinfo/info.xml') && str_contains($path, '/tmp/extract/')), CheckRunner::CONTEXT_CLI)
			->willReturn($checks);

		$run = $this->signedDryRun(true, null, $runner);

		self::assertNull($run['error']);
		self::assertSame($checks, $run['result']['checks'] ?? null);
		self::assertSame([], $run['writes']);
	}

	public function testAFailedValidationRunsNoChecks(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('Runs on the unit-only bootstrap, whose archive and X509 classes and server container it controls.');
		}
		$runner = $this->createMock(CheckRunner::class);
		$runner->expects(self::never())->method('run');

		self::assertNotNull($this->signedDryRun(false, null, $runner)['error']);
	}

	/**
	 * Runs one signed dry run of someapp 2.0.0 over an installed 1.0.0, with a
	 * self-signed certificate the scripted X509 accepts.
	 *
	 * @return array{error: ?string, result: ?array<string, mixed>, writes: list<array<int, mixed>>, apps: array<string, ?string>, infoPaths: list<string>, root: string}
	 */
	private function signedDryRun(bool $compatible, ?string $dependencyError, ?CheckRunner $runner = null): array {
		$root = sys_get_temp_dir() . '/versioniq-dryrun-validate-' . bin2hex(random_bytes(4));
		mkdir($root . '/tmp/extract', 0777, true);
		mkdir($root . '/apps/someapp', 0777, true);
		mkdir($root . '/server/resources/codesigning', 0777, true);
		file_put_contents($root . '/apps/someapp/old.txt', 'old');
		file_put_contents($root . '/server/resources/codesigning/root.crt', "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n");
		file_put_contents($root . '/server/resources/codesigning/root.crl', 'CRL');
		InstallerGoldenSupport::tarGz($root . '/archive.tar.gz', [
			'someapp/appinfo/info.xml' => "<?xml version=\"1.0\"?>\n<info><id>someapp</id><version>2.0.0</version><name>Some App</name></info>\n",
			'someapp/lib/Plugin.php' => "<?php // new\n",
		]);
		$archive = (string)file_get_contents($root . '/archive.tar.gz');

		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
		$csr = openssl_csr_new(['commonName' => 'someapp'], $key, ['digest_alg' => 'sha256']);
		self::assertNotFalse($csr);
		$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
		self::assertNotFalse($cert);
		openssl_x509_export($cert, $pem);
		openssl_sign($archive, $signature, $key, OPENSSL_ALGO_SHA512);

		$writes = [];
		$infoPaths = [];
		$savedServerRoot = \OC::$SERVERROOT;
		$savedRoots = \OC::$APPSROOTS;
		\OC::$SERVERROOT = $root . '/server';
		\OC::$APPSROOTS = [['path' => $root . '/apps', 'url' => '/apps', 'writable' => true]];
		X509::$handler = static fn (X509 $x509, string $method): mixed => match ($method) {
			'loadX509' => ['tbsCertificate' => ['serialNumber' => new class {
				public function toString(): string {
					return '42';
				}
			}]],
			'getRevoked' => false,
			default => true,
		};
		\OC_App::$dependencyCheck = $dependencyError === null ? null : static function () use ($dependencyError): void {
			throw new Exception($dependencyError);
		};
		InstallerGoldenSupport::useServices($this->signedServices($root, $archive, $compatible, $writes, $infoPaths));

		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(ArtifactCache::class),
			$runner,
		);
		$release = ['download' => 'https://apps.example.org/someapp-2.0.0.tar.gz', 'version' => '2.0.0', 'certificate' => (string)$pem, 'signature' => base64_encode((string)$signature)];

		try {
			$result = $installer->installFromSelectedRelease('someapp', $release, true, CheckRunner::CONTEXT_CLI);
			$error = null;
		} catch (Exception $failure) {
			$result = null;
			$error = $failure->getMessage();
		} finally {
			X509::$handler = null;
			\OC_App::$dependencyCheck = null;
			InstallerGoldenSupport::resetServices();
			\OC::$SERVERROOT = $savedServerRoot;
			\OC::$APPSROOTS = $savedRoots;
		}
		$apps = InstallerGoldenSupport::snapshot($root . '/apps');
		InstallerGoldenSupport::removeTree($root);

		return ['error' => $error, 'result' => $result, 'writes' => $writes, 'apps' => $apps, 'infoPaths' => $infoPaths, 'root' => $root];
	}

	/**
	 * The server services behind OCP\Server::get(), recording every config write.
	 *
	 * @param list<array<int, mixed>> $writes
	 * @param list<string> $infoPaths
	 * @return array<string, object>
	 */
	private function signedServices(string $root, string $archive, bool $compatible, array &$writes, array &$infoPaths): array {
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('en');
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$version = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ServerVersion::class, 'versionString'))->setValue($version, '33.0.1');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.0.0');
		$appManager->method('getAppPath')->willReturn($root . '/apps/someapp');
		$appManager->method('getAppInfoByPath')->willReturnCallback(static function (string $path) use (&$infoPaths): array {
			$infoPaths[] = $path;

			return ['id' => 'someapp', 'name' => 'Some App', 'version' => '2.0.0'];
		});
		$appManager->method('isAppCompatible')->willReturn($compatible);
		$appManager->expects(self::never())->method('enableApp');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn([]);
		$config->method('setSystemValue')->willReturnCallback(static function (string $key, mixed $value) use (&$writes): void {
			$writes[] = ['system', $key, $value];
		});
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('yes');
		foreach (['setValueString', 'setValueBool', 'setValueInt', 'deleteKey'] as $write) {
			$appConfig->method($write)->willReturnCallback(static function (mixed ...$args) use (&$writes, $write): bool {
				$writes[] = [$write, ...$args];

				return true;
			});
		}

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturn($root . '/tmp/download.tar.gz');
		$temp->method('getTemporaryFolder')->willReturn($root . '/tmp/extract');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($archive): IResponse {
			file_put_contents((string)$options['sink'], $archive);

			return $this->createMock(IResponse::class);
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return [
			IFactory::class => $factory,
			ServerVersion::class => $version,
			FilenameValidator::class => $this->createMock(FilenameValidator::class),
			IAppManager::class => $appManager,
			IConfig::class => $config,
			IAppConfig::class => $appConfig,
			ITempManager::class => $temp,
			IClientService::class => $clients,
		];
	}
}
