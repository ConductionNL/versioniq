<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

require_once __DIR__ . '/../../Support/AuditArgs.php';

use Error;
use Exception;
use OC\Files\FilenameValidator;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Tests\Unit\Support\AuditArgs;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\ServerVersion;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use Throwable;
use WeakMap;

require_once __DIR__ . '/InstallerGoldenSupport.php';

/**
 * Golden test for SelectedReleaseInstallerService::installFromSelectedRelease()
 * and replaceWithSelectedRelease(): every release guard, certificate check,
 * download and cache path, archive shape, signature outcome, destination, dry
 * run, copy, validation and finalize outcome runs against recording doubles,
 * a scripted X509 stub, real OpenSSL signatures and a real temporary
 * filesystem. The returned value or thrown failure, every collaborator call,
 * the debug log, the PHP warnings and the resulting files are compared, case
 * by case as JSON text, with fixtures/selected-release-install.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\SelectedReleaseInstallerService
 * @covers \OCA\Versioniq\Service\Installer\ReplacesAppFolder
 * @uses \OCA\Versioniq\Service\Installer\InstalledFolder
 * @uses \OCA\Versioniq\Service\Installer\InstallFailure
 * @uses \OCA\Versioniq\Service\Installer\ReleaseTarget
 * @uses \OCA\Versioniq\Service\Installer\SignedInstall
 * @uses \OCA\Versioniq\Service\Installer\SignedRelease
 * @uses \OCA\Versioniq\Service\Installer\SignedReleaseRun
 * @uses \OCA\Versioniq\Service\Installer\TempArchive
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 */
final class SelectedReleaseInstallGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../fixtures/selected-release-install.golden.json';

	private const DOWNLOAD = 'https://github.com/acme/someapp/releases/download/v2.0.0/someapp.tar.gz';

	private const BASE = [
		'entry' => 'install',
		'appId' => 'someapp',
		'release' => ['download' => self::DOWNLOAD, 'signature' => '<sig>', 'certificate' => '<cert>', 'version' => '2.0.0'],
		'dryRun' => false,
		'installed' => '1.0.0',
		'roots' => true,
		'x509' => 'ok',
		'cn' => 'someapp',
		'temp' => true,
		'download' => 'ok',
		'cache' => null,
		'archive' => 'tar',
		'sig' => 'ok',
		'appPath' => 'existing',
		'backupExists' => false,
		'appsRoot' => 'writable',
		'appInfo' => 'ok',
		'compatible' => true,
		'overwrite' => [],
		'diff' => ['Version2Date20260101'],
		'copy' => 'ok',
		'finalize' => 'ok',
		'enabled' => 'yes',
		'user' => 'admin',
		'repeat' => false,
	];

	/** @var array<string, string> CN => PEM certificate */
	private static array $certs = [];

	private static ?\OpenSSLAsymmetricKey $key = null;

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	private string $root = '';

	private string $archiveBytes = '';

	/** @var array<string, string> text => mask */
	private array $masks = [];

	public static function setUpBeforeClass(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
		self::$key = $key;
		foreach (['someapp' => ['commonName' => 'someapp'], 'someapp2' => ['commonName' => 'someapp', 'organizationName' => 'Second'], 'otherapp' => ['commonName' => 'otherapp'], 'none' => ['organizationName' => 'Acme']] as $name => $dn) {
			$csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
			self::assertNotFalse($csr);
			$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
			self::assertNotFalse($cert);
			openssl_x509_export($cert, $pem);
			self::$certs[$name] = (string)$pem;
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$list = ['plain' => []];
		$release = static fn (array $over): array => ['release' => array_replace(self::BASE['release'], $over)];

		// Request and release guards.
		$list['invalid app id'] = ['appId' => 'Some App'];
		$list['invalid app id, inner capital'] = ['appId' => 'someApp'];
		$list['invalid app id, dry run'] = ['appId' => '1app', 'dryRun' => true];
		foreach (['download', 'signature', 'certificate', 'version'] as $field) {
			$list["no {$field}"] = ['release' => array_diff_key(self::BASE['release'], [$field => true])];
			$list["empty {$field}"] = $release([$field => '']);
			$list["{$field} not a string"] = $release([$field => 5]);
			$list["no {$field}, dry run"] = ['release' => array_diff_key(self::BASE['release'], [$field => true]), 'dryRun' => true];
		}

		// Installed version.
		foreach (['', 'throw', '2.0.0', '3.0.0', '2.0.0-beta'] as $installed) {
			foreach ([false, true] as $dryRun) {
				$list["installed {$installed}, dry run " . json_encode($dryRun)] = ['installed' => $installed, 'dryRun' => $dryRun];
			}
		}
		$list['downgrade, no diff'] = ['installed' => '3.0.0', 'diff' => null];
		$list['downgrade, no diff, dry run'] = ['installed' => '3.0.0', 'diff' => null, 'dryRun' => true];
		$list['downgrade, version read fails second time'] = ['installed' => 'second-throw'];

		// Certificate.
		foreach (['no-crt', 'no-crl'] as $roots) {
			$list["roots {$roots}"] = ['roots' => $roots];
		}
		foreach (['parse-fail', 'crl-invalid', 'no-serial', 'serial-no-string', 'revoked', 'untrusted'] as $x509) {
			$list["x509 {$x509}"] = ['x509' => $x509];
			$list["x509 {$x509}, dry run"] = ['x509' => $x509, 'dryRun' => true];
		}
		$list['cert for another app'] = ['cn' => 'otherapp'];
		$list['cert without a common name'] = ['cn' => 'none'];

		// Temp paths, download and the cache fallback.
		$list['no temp paths'] = ['temp' => false];
		$list['no temp file'] = ['temp' => 'no-file'];
		$list['no temp folder'] = ['temp' => 'no-folder'];
		$list['download fails, no cache'] = ['download' => 'throw'];
		foreach (['same', 'second-cert', 'no-cert', 'no-signature', 'bad-cert', 'other-cert'] as $cache) {
			$list["download fails, cache {$cache}"] = ['download' => 'throw', 'cache' => $cache];
			$list["download fails, cache {$cache}, dry run"] = ['download' => 'throw', 'cache' => $cache, 'dryRun' => true];
		}
		$list['download fails, cache same, request signature bad'] = ['download' => 'throw', 'cache' => 'same', 'sig' => 'bad'];

		// Archive shapes and the signature.
		foreach (['junk', 'two-dirs', 'flat', 'no-info', 'bad-xml', 'wrong-id', 'wrong-version'] as $archive) {
			$list["archive {$archive}"] = ['archive' => $archive];
		}
		foreach (['bad', 'not-base64'] as $sig) {
			$list["signature {$sig}"] = ['sig' => $sig];
			$list["signature {$sig}, dry run"] = ['sig' => $sig, 'dryRun' => true];
		}

		// Destination.
		foreach ([false, true] as $dryRun) {
			$suffix = ', dry run ' . json_encode($dryRun);
			$list['fresh install' . $suffix] = ['appPath' => 'missing', 'installed' => '', 'dryRun' => $dryRun];
			$list['fresh install, no writable root' . $suffix] = ['appPath' => 'missing', 'installed' => '', 'appsRoot' => 'none', 'dryRun' => $dryRun];
			$list['fresh install, root not writable' . $suffix] = ['appPath' => 'missing', 'installed' => '', 'appsRoot' => 'readonly', 'dryRun' => $dryRun];
			$list['parent missing' . $suffix] = ['appPath' => 'no-parent', 'dryRun' => $dryRun];
			$list['path known but folder gone' . $suffix] = ['appPath' => 'gone', 'dryRun' => $dryRun];
			$list['old backup present' . $suffix] = ['backupExists' => true, 'dryRun' => $dryRun];
		}

		// Copy, validation and finalize.
		$list['forbidden file skipped'] = ['copy' => 'forbidden'];
		$list['copy fails'] = ['copy' => 'throw'];
		$list['copy fails, fresh install'] = ['copy' => 'throw', 'appPath' => 'missing', 'installed' => ''];
		$list['copy fails, folder gone'] = ['copy' => 'throw', 'appPath' => 'gone'];
		$list['app info null'] = ['appInfo' => null];
		$list['app info other id'] = ['appInfo' => 'other'];
		$list['app info null, fresh install'] = ['appInfo' => null, 'appPath' => 'missing', 'installed' => ''];
		$list['incompatible'] = ['compatible' => false];
		$list['incompatible, no name'] = ['compatible' => false, 'appInfo' => 'no-name'];
		$list['incompatible, overwrite'] = ['compatible' => false, 'overwrite' => ['someapp']];
		$list['overwrite listed'] = ['overwrite' => ['someapp']];
		foreach (['exception', 'error', 'backup-gone'] as $finalize) {
			$list["finalize {$finalize}"] = ['finalize' => $finalize];
			$list["finalize {$finalize}, fresh install"] = ['finalize' => $finalize, 'appPath' => 'missing', 'installed' => ''];
			$list["finalize {$finalize}, downgrade"] = ['finalize' => $finalize, 'installed' => '3.0.0'];
		}
		$list['previously disabled'] = ['enabled' => 'no'];
		$list['previously disabled, fresh install'] = ['enabled' => 'no', 'appPath' => 'missing', 'installed' => ''];
		$list['downgrade installs'] = ['installed' => '3.0.0'];
		$list['downgrade from cache'] = ['installed' => '3.0.0', 'download' => 'throw', 'cache' => 'same'];
		$list['no user'] = ['user' => null];
		$list['no user, failure'] = ['user' => null, 'finalize' => 'exception'];
		$list['second run on the same installer'] = ['repeat' => true];
		$list['second run after a downgrade dry run'] = ['repeat' => 'downgrade'];

		// replaceWithSelectedRelease() called directly.
		foreach ([false, true] as $dryRun) {
			$suffix = ', dry run ' . json_encode($dryRun);
			$list['replace' . $suffix] = ['entry' => 'replace', 'dryRun' => $dryRun];
			$list['replace, fresh install' . $suffix] = ['entry' => 'replace', 'appPath' => 'missing', 'installed' => '', 'dryRun' => $dryRun];
			$list['replace, downgrade' . $suffix] = ['entry' => 'replace', 'installed' => '3.0.0', 'dryRun' => $dryRun];
		}
		$list['replace, no version'] = ['entry' => 'replace', 'release' => array_diff_key(self::BASE['release'], ['version' => true])];
		$list['replace, copy fails'] = ['entry' => 'replace', 'copy' => 'throw'];

		return $list;
	}

	public function testEveryScenarioMatchesTheGolden(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('The golden runs on the unit-only bootstrap, whose archive, X509 classes and server container it controls.');
		}

		$actual = [];
		foreach (self::scenarios() as $name => $over) {
			$actual[$name] = $this->runScenario(array_replace(self::BASE, $over));
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$lines = [];
			foreach ($actual as $name => $case) {
				$lines[] = json_encode($name) . ': ' . json_encode($case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			file_put_contents(self::FIXTURE, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'scenario list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
				"scenario {$name}",
			);
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array{outcome: string, apps: array<string, ?string>}}>
	 */
	public static function staleBackupCases(): array {
		$old = ['someapp' => null, 'someapp/lib' => null, 'someapp/lib/Plugin.php' => "<?php // old\n", 'someapp/old.txt' => 'old'];
		$new = [
			'someapp' => null,
			'someapp/appinfo' => null,
			'someapp/appinfo/info.xml' => "<?xml version=\"1.0\"?>\n<info><id>someapp</id><version>2.0.0</version><name>Some App</name></info>\n",
			'someapp/lib' => null,
			'someapp/lib/Plugin.php' => "<?php // new\n",
			'someapp/lib/forbidden.txt' => 'skip me',
		];

		return [
			'install succeeds' => [[], ['outcome' => 'installed', 'apps' => $new]],
			'copy fails' => [['copy' => 'throw'], ['outcome' => 'reverted', 'apps' => $old]],
			'finalize fails' => [['finalize' => 'exception'], ['outcome' => 'installed-but-broken', 'apps' => $old]],
		];
	}

	/**
	 * A backup folder left behind by an earlier run is removed before the live
	 * folder is moved aside: the install goes ahead, a failure restores the
	 * previous files (not the stale ones), and no backup folder remains.
	 *
	 * @dataProvider staleBackupCases
	 * @param array<string, mixed> $over
	 * @param array{outcome: string, apps: array<string, ?string>} $expected
	 */
	public function testAStaleBackupFolderIsReplaced(array $over, array $expected): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('The golden runs on the unit-only bootstrap, whose archive, X509 classes and server container it controls.');
		}

		$case = $this->runScenario(array_replace(self::BASE, ['backupExists' => true], $over));

		$outcome = $case['result']['status'] ?? $case['error']['outcome'] ?? $case['error']['message'];
		self::assertSame($expected['outcome'], $outcome);
		self::assertSame($expected['apps'], $case['apps']);
		self::assertStringNotContainsString('stale.txt', (string)json_encode($case['calls']));
		if (isset($case['error'])) {
			self::assertSame('clean', $case['error']['restore']);
		}
	}

	/**
	 * openssl_verify() returns -1, not 0, when it cannot check a signature at
	 * all (here: bytes that are no DER signature, under an EC certificate).
	 * Only 1 means verified, so the install stops and the old files stay.
	 */
	public function testASignatureCheckErrorIsNotAccepted(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('The golden runs on the unit-only bootstrap, whose archive, X509 classes and server container it controls.');
		}
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
		$csr = openssl_csr_new(['commonName' => 'someapp'], $key, ['digest_alg' => 'sha256']);
		self::assertNotFalse($csr);
		$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
		self::assertNotFalse($cert);
		openssl_x509_export($cert, $pem);
		self::assertSame(-1, openssl_verify('probe', 'xx', (string)$pem, OPENSSL_ALGO_SHA512));

		$release = ['certificate' => (string)$pem, 'signature' => base64_encode('xx')] + self::BASE['release'];
		$case = $this->runScenario(array_replace(self::BASE, ['release' => $release]));

		self::assertSame('Release signature verification failed.', $case['error']['message'] ?? null);
		self::assertSame(['someapp' => null, 'someapp/lib' => null, 'someapp/lib/Plugin.php' => "<?php // old\n", 'someapp/old.txt' => 'old'], $case['apps']);
	}

	/**
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	private function runScenario(array $s): array {
		$this->calls = [];
		$this->root = sys_get_temp_dir() . '/versioniq-sel-golden-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/src', 0777, true);
		mkdir($this->root . '/tmp/extract', 0777, true);
		mkdir($this->root . '/apps', 0777, true);
		mkdir($this->root . '/server/resources/codesigning', 0777, true);
		$savedRoots = \OC::$APPSROOTS;
		$savedServerRoot = \OC::$SERVERROOT;
		$savedErrors = libxml_use_internal_errors(true);

		try {
			$this->archiveBytes = $this->buildArchive((string)$s['archive']);
			$s['release'] = $this->resolveRelease((array)$s['release'], $s);
			$this->prepareApps($s);
			$this->prepareRoots($s);
			\OC::$APPSROOTS = $this->appsRoots((string)$s['appsRoot']);
			\OC::$SERVERROOT = $this->root . '/server';
			X509::$handler = $this->x509Handler($s);
			InstallerGoldenSupport::useServices($this->serverServices($s));
			$installer = $this->installer($s);
			if ($s['repeat'] !== false) {
				$this->runFirst($installer, $s);
			}
			$warnings = [];
			set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
				$warnings[] = $message;

				return true;
			});

			try {
				$result = $s['entry'] === 'replace'
					? ['backup' => $installer->replaceWithSelectedRelease((string)$s['appId'], (array)$s['release'], (bool)$s['dryRun'])]
					: $installer->installFromSelectedRelease((string)$s['appId'], (array)$s['release'], (bool)$s['dryRun']);
				$outcome = ['result' => $this->normalize($result)];
			} catch (Throwable $error) {
				$outcome = ['error' => $this->describeError($error)];
			} finally {
				restore_error_handler();
			}
			$outcome['warnings'] = $warnings;
			$outcome['debug'] = $this->normalize($installer->getDebugLog());
			if (isset($outcome['result']['debug']) && $outcome['result']['debug'] === $outcome['debug']) {
				$outcome['result']['debug'] = '<debug log>';
			}
			$outcome['orphanedMigrations'] = $installer->getOrphanedMigrations();
			$outcome['calls'] = $this->calls;
			$outcome['apps'] = InstallerGoldenSupport::snapshot($this->root . '/apps');
			$outcome['tmp'] = $this->tmpSnapshot();

			return $this->mask($outcome);
		} finally {
			X509::$handler = null;
			InstallerGoldenSupport::resetServices();
			\OC::$APPSROOTS = $savedRoots;
			\OC::$SERVERROOT = $savedServerRoot;
			libxml_clear_errors();
			libxml_use_internal_errors($savedErrors);
			@chmod($this->root . '/apps', 0777);
			InstallerGoldenSupport::removeTree($this->root);
		}
	}

	/**
	 * Runs one dry run first (of a downgrade when asked), so the recorded run
	 * is the installer's second.
	 *
	 * @param array<string, mixed> $s
	 */
	private function runFirst(SelectedReleaseInstallerService $installer, array $s): void {
		try {
			$installer->installFromSelectedRelease('someapp', (array)$s['release'], true);
		} catch (Throwable) {
		}
		$this->calls = [];
	}

	/**
	 * @param array<string, mixed> $release
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	private function resolveRelease(array $release, array $s): array {
		$cert = self::$certs[(string)$s['cn']];
		$this->masks = [$cert => '<cert:' . $s['cn'] . '>'];
		foreach (self::$certs as $name => $pem) {
			$this->masks[$pem] = '<cert:' . $name . '>';
		}
		openssl_sign($this->archiveBytes, $good, self::$key, OPENSSL_ALGO_SHA512);
		openssl_sign('other bytes', $bad, self::$key, OPENSSL_ALGO_SHA512);
		$this->masks[base64_encode((string)$good)] = '<sig:ok>';
		$this->masks[base64_encode((string)$bad)] = '<sig:bad>';
		$signature = $s['sig'] === 'bad' ? $bad : $good;
		foreach ($release as $field => $value) {
			if ($value === '<cert>') {
				$release[$field] = $cert;
			} elseif ($value === '<sig>') {
				$release[$field] = $s['sig'] === 'not-base64' ? '***' : base64_encode((string)$signature);
			}
		}

		return $release;
	}

	private function buildArchive(string $shape): string {
		$info = static fn (string $id, string $version): string => "<?xml version=\"1.0\"?>\n<info><id>{$id}</id><version>{$version}</version><name>Some App</name></info>\n";
		$good = [
			'someapp/appinfo/info.xml' => $info('someapp', '2.0.0'),
			'someapp/lib/Plugin.php' => "<?php // new\n",
			'someapp/lib/forbidden.txt' => 'skip me',
		];
		$files = match ($shape) {
			'two-dirs' => $good + ['other/readme.txt' => 'x'],
			'flat' => ['info.xml' => $info('someapp', '2.0.0')],
			'no-info' => ['someapp/lib/Plugin.php' => "<?php\n"],
			'bad-xml' => ['someapp/appinfo/info.xml' => '<info><id>someapp'],
			'wrong-id' => ['someapp/appinfo/info.xml' => $info('otherapp', '2.0.0')],
			'wrong-version' => ['someapp/appinfo/info.xml' => $info('someapp', '1.9.0')],
			default => $good,
		};
		$path = $this->root . '/src/archive.tar.gz';
		if ($shape === 'junk') {
			file_put_contents($path, 'not an archive');
		} else {
			InstallerGoldenSupport::tarGz($path, $files);
		}

		return (string)file_get_contents($path);
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function prepareApps(array $s): void {
		if ($s['appPath'] === 'existing') {
			mkdir($this->root . '/apps/someapp/lib', 0777, true);
			file_put_contents($this->root . '/apps/someapp/lib/Plugin.php', "<?php // old\n");
			file_put_contents($this->root . '/apps/someapp/old.txt', 'old');
		}
		if ($s['backupExists'] === true) {
			mkdir($this->root . '/apps/someapp.appversion-backup', 0777, true);
			file_put_contents($this->root . '/apps/someapp.appversion-backup/stale.txt', 'stale');
		}
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function prepareRoots(array $s): void {
		$dir = $this->root . '/server/resources/codesigning';
		if ($s['roots'] !== 'no-crt') {
			file_put_contents($dir . '/root.crt', "-----BEGIN CERTIFICATE-----\nROOT-A\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nROOT-B\n-----END CERTIFICATE-----\n");
		}
		if ($s['roots'] !== 'no-crl') {
			file_put_contents($dir . '/root.crl', 'ROOT-CRL');
		}
	}

	/**
	 * @return array<int, array{path: string, url: string, writable: bool}>
	 */
	private function appsRoots(string $shape): array {
		$apps = $this->root . '/apps';
		if ($shape === 'readonly') {
			chmod($apps, 0555);
		}

		return match ($shape) {
			'none' => [],
			default => [['path' => $this->root . '/ro', 'url' => '/ro', 'writable' => false], ['path' => $apps, 'url' => '/apps', 'writable' => true]],
		};
	}

	/**
	 * A scripted X509: the instance that loads a CRL answers the CRL checks,
	 * the one that loads the app certificate answers the chain check.
	 *
	 * @param array<string, mixed> $s
	 */
	private function x509Handler(array $s): \Closure {
		/** @var WeakMap<X509, string> $roles */
		$roles = new WeakMap();
		$ids = new WeakMap();
		$next = 0;

		return function (X509 $x509, string $method, array $args) use ($s, $roles, $ids, &$next): mixed {
			if (!isset($ids[$x509])) {
				$ids[$x509] = $next++;
			}
			$this->record('x509#' . $ids[$x509] . '.' . $method, $args);
			$x509ShapeFor = static fn (string $cert): string => str_contains($cert, 'CACHED-BAD') ? 'parse-fail' : (string)$s['x509'];
			switch ($method) {
				case 'loadX509':
					$roles[$x509] = 'cert';
					$shape = $x509ShapeFor((string)$args[0]);
					if ($shape === 'parse-fail') {
						return false;
					}
					$serial = match ($shape) {
						'no-serial' => null,
						'serial-no-string' => new \stdClass(),
						default => new class {
							public function toString(): string {
								return '4242';
							}
						},
					};

					return ['tbsCertificate' => ['serialNumber' => $serial]];
				case 'loadCRL':
					$roles[$x509] = 'crl';

					return true;
				case 'validateSignature':
					return ($roles[$x509] ?? '') === 'crl' ? $s['x509'] !== 'crl-invalid' : $s['x509'] !== 'untrusted';
				case 'getRevoked':
					return $s['x509'] === 'revoked' ? ['revocationDate' => 'x'] : false;
				default:
					return true;
			}
		};
	}

	/**
	 * @param array<string, mixed> $s
	 * @return array<string, object>
	 */
	private function serverServices(array $s): array {
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('nl');
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => '[t] ' . vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app) use ($l): IL10N {
			$this->record('l10n.get', [$app]);

			return $l;
		});
		$version = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ServerVersion::class, 'versionString'))->setValue($version, '33.0.1');
		$validator = $this->createMock(FilenameValidator::class);
		$validator->method('isForbidden')->willReturnCallback(function (string $path) use ($s): bool {
			$this->record('filename.isForbidden', [$path]);
			if ($s['copy'] === 'throw' && str_ends_with($path, 'Plugin.php')) {
				throw new Exception('Validator failed.');
			}

			return $s['copy'] === 'forbidden' && str_ends_with($path, 'forbidden.txt');
		});

		return [
			IFactory::class => $factory,
			ServerVersion::class => $version,
			FilenameValidator::class => $validator,
			IAppManager::class => $this->appManager($s),
			IConfig::class => $this->config($s),
			IAppConfig::class => $this->appConfig($s),
			ITempManager::class => $this->tempManager($s),
			IClientService::class => $this->clientService($s),
		];
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function config(array $s): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(function (string $key, mixed $default) use ($s): mixed {
			$this->record('config.value', [$key, $default]);

			return $s['overwrite'];
		});

		return $config;
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function appConfig(array $s): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default) use ($s): string {
			$this->record('appConfig.get', [$app, $key, $default]);

			return $key === 'enabled' ? (string)$s['enabled'] : '2.0.0';
		});

		return $appConfig;
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function tempManager(array $s): ITempManager {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (string $suffix) use ($s): string|false {
			$this->record('temp.file', [$suffix]);

			return $s['temp'] === true || $s['temp'] === 'no-folder' ? $this->root . '/tmp/archive' . $suffix : false;
		});
		$tempManager->method('getTemporaryFolder')->willReturnCallback(function (string $name) use ($s): string|false {
			$this->record('temp.folder', [$name]);

			return $s['temp'] === true || $s['temp'] === 'no-file' ? $this->root . '/tmp/extract' : false;
		});

		return $tempManager;
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function clientService(array $s): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($s): IResponse {
			$this->record('http.get', [$url, $options]);
			if ($s['download'] === 'throw') {
				throw new Exception('Connection refused.');
			}
			file_put_contents((string)$options['sink'], $this->archiveBytes);

			return $this->createMock(IResponse::class);
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturnCallback(function () use ($client): IClient {
			$this->record('http.newClient', []);

			return $client;
		});

		return $service;
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function appManager(array $s): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$versionReads = 0;
		$appManager->method('getAppVersion')->willReturnCallback(function (string $appId) use ($s, &$versionReads): string {
			$this->record('apps.getAppVersion', [$appId]);
			$versionReads++;
			if ($s['repeat'] === 'downgrade' && $versionReads <= 2) {
				return '3.0.0';
			}
			if ($s['installed'] === 'throw' || ($s['installed'] === 'second-throw' && $versionReads > 1)) {
				throw new Exception('not installed');
			}

			return $s['installed'] === 'second-throw' ? '3.0.0' : (string)$s['installed'];
		});
		$appManager->method('getAppPath')->willReturnCallback(function (string $appId, bool $ignoreCache = false) use ($s): string {
			$this->record('apps.getAppPath', [$appId, $ignoreCache]);
			if ($ignoreCache) {
				return $this->root . '/apps/someapp';
			}

			return match ($s['appPath']) {
				'missing' => throw new AppPathNotFoundException('no path'),
				'no-parent' => $this->root . '/nowhere/someapp',
				default => $this->root . '/apps/someapp',
			};
		});
		$appManager->method('getAppInfoByPath')->willReturnCallback(function (string $path, ?string $lang = null) use ($s): ?array {
			$this->record('apps.getAppInfoByPath', [$path, $lang]);

			return match ($s['appInfo']) {
				null => null,
				'other' => ['id' => 'otherapp'],
				'no-name' => ['id' => 'someapp', 'version' => '2.0.0'],
				default => ['id' => 'someapp', 'version' => '2.0.0', 'name' => 'Some App'],
			};
		});
		$appManager->method('isAppCompatible')->willReturnCallback(function (string $serverVersion, array $info, bool $ignoreMax) use ($s): bool {
			$this->record('apps.isAppCompatible', [$serverVersion, $info, $ignoreMax]);

			return (bool)$s['compatible'];
		});

		return $appManager;
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function installer(array $s): SelectedReleaseInstallerService {
		$userSession = $this->createMock(IUserSession::class);
		if ($s['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn((string)$s['user']);
			$userSession->method('getUser')->willReturn($user);
		}

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (mixed ...$args): void {
			$this->record('audit.record', AuditArgs::flatten($args));
		});

		$differ = $this->createMock(MigrationDiffer::class);
		$differ->method('diff')->willReturnCallback(function (?string $installed, ?string $target) use ($s): ?array {
			$this->record('differ.diff', [$installed, $target]);

			return $s['diff'];
		});

		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('fetch')->willReturnCallback(function (string $appId, string $version) use ($s): ?array {
			$this->record('cache.fetch', [$appId, $version]);

			return $this->cachedArtifact($s);
		});
		$cache->method('store')->willReturnCallback(function (string $appId, string $version, string $path, array $meta): void {
			$this->record('cache.store', [$appId, $version, $path, $meta, is_file($path) ? hash_file('sha256', $path) : 'missing']);
		});

		return new SelectedReleaseInstallerService($this->finalizer($s), $audit, $userSession, $differ, $cache);
	}

	/**
	 * @param array<string, mixed> $s
	 * @return ?array{content: string, meta: array<string, mixed>}
	 */
	private function cachedArtifact(array $s): ?array {
		if ($s['cache'] === null) {
			return null;
		}
		openssl_sign($this->archiveBytes, $signature, self::$key, OPENSSL_ALGO_SHA512);
		$meta = ['certificate' => self::$certs['someapp'], 'signature' => base64_encode((string)$signature)];
		$meta = match ($s['cache']) {
			'no-cert' => ['signature' => $meta['signature']],
			'no-signature' => ['certificate' => $meta['certificate'], 'signature' => 7],
			'bad-cert' => ['certificate' => 'CACHED-BAD', 'signature' => $meta['signature']],
			'other-cert' => ['certificate' => self::$certs['otherapp'], 'signature' => $meta['signature']],
			'second-cert' => ['certificate' => self::$certs['someapp2'], 'signature' => $meta['signature']],
			default => $meta,
		};

		return ['content' => $this->archiveBytes, 'meta' => $meta];
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function finalizer(array $s): InstallFinalizer {
		$finalizer = $this->createMock(InstallFinalizer::class);
		$finalizer->method('finalize')->willReturnCallback(function (mixed ...$args) use ($s): string {
			$this->record('finalizer.finalize', [...$args, InstallerGoldenSupport::snapshot($this->root . '/apps')]);

			return match ($s['finalize']) {
				'exception' => throw new Exception('Migration failed.'),
				'error' => throw new Error('Call to undefined method.'),
				'backup-gone' => $this->dropBackupAndFail(),
				default => 'someapp',
			};
		});

		return $finalizer;
	}

	private function dropBackupAndFail(): never {
		InstallerGoldenSupport::removeTree($this->root . '/apps/someapp.appversion-backup');

		throw new Exception('Repair step failed.');
	}

	/**
	 * @param array<int, mixed> $args
	 */
	private function record(string $method, array $args): void {
		$this->calls[] = [$method, ...array_map(fn (mixed $arg): mixed => $this->normalize($arg), $args)];
	}

	private function normalize(mixed $value): mixed {
		if (is_object($value)) {
			return 'object ' . $value::class;
		}
		if (is_array($value)) {
			return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
		}

		return $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function describeError(Throwable $error): array {
		$description = ['class' => $error::class, 'message' => $error->getMessage(), 'code' => $error->getCode()];
		if ($error instanceof InstallFailure) {
			$description += ['stage' => $error->getStage(), 'outcome' => $error->getOutcome(), 'restore' => $error->getRestoreState()];
		}
		$previous = $error->getPrevious();
		if ($previous !== null) {
			$description['previous'] = [$previous::class, $previous->getMessage()];
		}

		return $description;
	}

	/**
	 * @return array<string, ?string>
	 */
	private function tmpSnapshot(): array {
		$tree = InstallerGoldenSupport::snapshot($this->root . '/tmp');
		if (isset($tree['archive.tar.gz'])) {
			$tree['archive.tar.gz'] = $tree['archive.tar.gz'] === $this->archiveBytes ? '<archive>' : 'sha256:' . hash('sha256', $tree['archive.tar.gz']);
		}

		return $tree;
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private function mask(array $outcome): array {
		$json = (string)json_encode($outcome, JSON_UNESCAPED_SLASHES);
		$masks = $this->masks + [$this->root => '<root>', hash('sha256', $this->archiveBytes) => '<sha>'];
		foreach ($masks as $text => $mask) {
			$encoded = substr((string)json_encode((string)$text, JSON_UNESCAPED_SLASHES), 1, -1);
			$json = str_replace($encoded, $mask, $json);
		}

		/** @var array<string, mixed> */
		return json_decode($json, true);
	}
}
