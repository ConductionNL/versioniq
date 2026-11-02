<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Error;
use Exception;
use OC\Files\FilenameValidator;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

require_once __DIR__ . '/InstallerGoldenSupport.php';

/**
 * Golden test for ExternalReleaseInstallerService::installFromExternalRelease():
 * every guard, download and cache path, checksum outcome, archive shape,
 * destination, dry run, copy and finalize outcome runs against recording
 * doubles and a real temporary filesystem. The returned envelope or thrown
 * failure, every collaborator call, the debug log and the resulting files are
 * compared, case by case as JSON text, with
 * fixtures/external-release-install.golden.json. Set RECORD_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Service\ExternalReleaseInstallerService
 * @uses \OCA\Versioniq\Db\Pat
 * @uses \OCA\Versioniq\Service\Installer\ArchiveCheck
 * @uses \OCA\Versioniq\Service\Installer\ExternalInstall
 * @uses \OCA\Versioniq\Service\Installer\ExternalInstallOptions
 * @uses \OCA\Versioniq\Service\Installer\ExternalInstallRequest
 * @uses \OCA\Versioniq\Service\Installer\ExternalInstallRun
 * @uses \OCA\Versioniq\Service\Installer\InstallFailure
 * @uses \OCA\Versioniq\Service\Installer\ReleaseDownload
 * @uses \OCA\Versioniq\Service\Installer\ShaMismatchException
 * @uses \OCA\Versioniq\Service\Installer\StagedRelease
 * @uses \OCA\Versioniq\Service\Source\ForgeRegistry
 * @uses \OCA\Versioniq\Service\Source\Forge
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 * @uses \OCA\Versioniq\Service\Source\UntrustedSourceException
 */
final class ExternalReleaseInstallGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../fixtures/external-release-install.golden.json';

	private const DOWNLOAD = 'https://github.com/acme/someapp/releases/download/v2.0.0/someapp.tar.gz';
	private const SHA_URL = 'https://github.com/acme/someapp/releases/download/v2.0.0/someapp.tar.gz.sha256';
	private const OTHER_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private const BASE = [
		'appId' => 'someapp',
		'version' => '2.0.0',
		'release' => ['download' => self::DOWNLOAD, 'sha256Url' => self::SHA_URL],
		'binding' => 'github',
		'dryRun' => false,
		'acceptNewSha' => false,
		'installed' => '1.0.0',
		'trusted' => true,
		'temp' => true,
		'pat' => true,
		'user' => 'admin',
		'download' => 'ok',
		'archive' => 'tar',
		'cache' => null,
		'recorded' => null,
		'sha' => 'match',
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
		'repeat' => false,
	];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	private string $root = '';

	private string $archiveBytes = '';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$list = ['plain' => []];

		// Request guards and the allowlist.
		$list['invalid app id'] = ['appId' => 'Some App'];
		$list['invalid app id, dry run'] = ['appId' => '1app', 'dryRun' => true];
		$list['invalid app id, inner capital'] = ['appId' => 'someApp'];
		$list['invalid app id, inner space'] = ['appId' => 'some app'];
		$list['untrusted'] = ['trusted' => false];
		$list['untrusted, dry run'] = ['trusted' => false, 'dryRun' => true];
		$list['no download'] = ['release' => ['sha256Url' => self::SHA_URL]];
		$list['download not a string'] = ['release' => ['download' => 5]];
		$list['empty download'] = ['release' => ['download' => '']];
		$list['no temp paths'] = ['temp' => false];
		$list['no temp file'] = ['temp' => 'no-file'];
		$list['no temp folder'] = ['temp' => 'no-folder'];
		$list['second run on the same installer'] = ['repeat' => true];
		$list['second run after a failed run'] = ['repeat' => true, 'download' => 'throw', 'cache' => 'same'];

		// Installed version.
		foreach (['', 'throw', '2.0.0', '3.0.0', '2.0.0-beta'] as $installed) {
			foreach ([false, true] as $dryRun) {
				$list["installed {$installed}, dry run " . json_encode($dryRun)] = ['installed' => $installed, 'dryRun' => $dryRun];
			}
		}
		$list['downgrade, no diff'] = ['installed' => '3.0.0', 'diff' => null];
		$list['downgrade, no diff, dry run'] = ['installed' => '3.0.0', 'diff' => null, 'dryRun' => true];

		// Tokens and users.
		$list['no pat'] = ['pat' => false];
		$list['no user'] = ['user' => null];
		$list['no user, no pat'] = ['user' => null, 'pat' => false];
		$list['appstore binding'] = ['binding' => 'appstore'];
		$list['codeberg binding'] = ['binding' => 'codeberg'];
		$list['asset on another host'] = ['release' => ['download' => 'https://cdn.example.org/someapp.tar.gz', 'sha256Url' => self::SHA_URL]];
		$list['checksum on another host'] = ['release' => ['download' => self::DOWNLOAD, 'sha256Url' => 'https://cdn.example.org/someapp.sha256']];

		// Download and the cache fallback.
		$list['download fails, no cache'] = ['download' => 'throw'];
		$list['download fails, cache hit'] = ['download' => 'throw', 'cache' => 'same'];
		$list['download fails, cache hit, dry run'] = ['download' => 'throw', 'cache' => 'same', 'dryRun' => true];
		$list['download fails, cache hit, recorded match'] = ['download' => 'throw', 'cache' => 'same', 'recorded' => 'match'];
		$list['download fails, cache hit, recorded other'] = ['download' => 'throw', 'cache' => 'same', 'recorded' => 'other'];

		// Recorded digest and the sibling checksum.
		foreach ([null, 'match', 'other'] as $recorded) {
			foreach ([false, true] as $accept) {
				foreach (['match', 'mismatch', 'none'] as $sha) {
					foreach ([false, true] as $dryRun) {
						$list['recorded ' . json_encode($recorded) . ', accept ' . json_encode($accept) . ", sha {$sha}, dry run " . json_encode($dryRun)] = [
							'recorded' => $recorded,
							'acceptNewSha' => $accept,
							'sha' => $sha,
							'dryRun' => $dryRun,
						];
					}
				}
			}
		}
		foreach (['throw', 'status404', 'bad', 'named', 'upper'] as $sha) {
			$list["sha {$sha}"] = ['sha' => $sha];
			$list["sha {$sha}, no pat"] = ['sha' => $sha, 'pat' => false];
		}
		$list['sha url empty'] = ['release' => ['download' => self::DOWNLOAD, 'sha256Url' => '']];
		$list['sha url not a string'] = ['release' => ['download' => self::DOWNLOAD, 'sha256Url' => 7]];
		$list['accepted with warning'] = ['recorded' => 'other', 'acceptNewSha' => true, 'sha' => 'none'];

		// Archive shapes.
		foreach (['zip', 'junk', 'two-dirs', 'flat', 'no-info', 'bad-xml', 'wrong-id', 'wrong-version'] as $archive) {
			$list["archive {$archive}"] = ['archive' => $archive];
		}

		// App info validation.
		$list['app info null'] = ['appInfo' => null];
		$list['app info other id'] = ['appInfo' => 'other'];
		$list['incompatible'] = ['compatible' => false];
		$list['incompatible, no name'] = ['compatible' => false, 'appInfo' => 'no-name'];
		$list['incompatible, overwrite'] = ['compatible' => false, 'overwrite' => ['someapp']];
		$list['overwrite listed'] = ['overwrite' => ['someapp']];

		// Destination.
		foreach ([false, true] as $dryRun) {
			$list['fresh install, dry run ' . json_encode($dryRun)] = ['appPath' => 'missing', 'installed' => '', 'dryRun' => $dryRun];
			$list['fresh install, no writable root, dry run ' . json_encode($dryRun)] = ['appPath' => 'missing', 'installed' => '', 'appsRoot' => 'none', 'dryRun' => $dryRun];
			$list['fresh install, root not writable, dry run ' . json_encode($dryRun)] = ['appPath' => 'missing', 'installed' => '', 'appsRoot' => 'readonly', 'dryRun' => $dryRun];
			$list['fresh install, root not flagged writable, dry run ' . json_encode($dryRun)] = ['appPath' => 'missing', 'installed' => '', 'appsRoot' => 'flag-false', 'dryRun' => $dryRun];
			$list['parent missing, dry run ' . json_encode($dryRun)] = ['appPath' => 'no-parent', 'dryRun' => $dryRun];
			$list['path known but folder gone, dry run ' . json_encode($dryRun)] = ['appPath' => 'gone', 'dryRun' => $dryRun];
		}
		$list['old backup present'] = ['backupExists' => true];
		$list['old backup present, dry run'] = ['backupExists' => true, 'dryRun' => true];

		// Copy and finalize.
		$list['forbidden file skipped'] = ['copy' => 'forbidden'];
		$list['copy fails'] = ['copy' => 'throw'];
		$list['copy fails, fresh install'] = ['copy' => 'throw', 'appPath' => 'missing', 'installed' => ''];
		$list['copy fails, folder gone'] = ['copy' => 'throw', 'appPath' => 'gone'];
		foreach (['exception', 'error', 'backup-gone'] as $finalize) {
			$list["finalize {$finalize}"] = ['finalize' => $finalize];
			$list["finalize {$finalize}, fresh install"] = ['finalize' => $finalize, 'appPath' => 'missing', 'installed' => ''];
			$list["finalize {$finalize}, downgrade"] = ['finalize' => $finalize, 'installed' => '3.0.0'];
		}
		$list['empty version'] = ['version' => '', 'archive' => 'empty-version'];
		$list['empty version, dry run'] = ['version' => '', 'archive' => 'empty-version', 'dryRun' => true];
		$list['previously disabled'] = ['enabled' => 'no'];
		$list['previously disabled, fresh install'] = ['enabled' => 'no', 'appPath' => 'missing', 'installed' => ''];
		$list['downgrade installs'] = ['installed' => '3.0.0'];
		$list['recorded other accepted, downgrade'] = ['installed' => '3.0.0', 'recorded' => 'other', 'acceptNewSha' => true];
		$list['recorded other accepted, finalize fails'] = ['recorded' => 'other', 'acceptNewSha' => true, 'finalize' => 'exception'];

		return $list;
	}

	public function testEveryScenarioMatchesTheGolden(): void {
		if (!InstallerGoldenSupport::isUnitOnly()) {
			$this->markTestSkipped('The golden runs on the unit-only bootstrap, whose archive classes and server container it controls.');
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
	 * @param array<string, mixed> $s
	 * @return array<string, mixed>
	 */
	private function runScenario(array $s): array {
		$this->calls = [];
		$this->root = sys_get_temp_dir() . '/versioniq-ext-golden-' . bin2hex(random_bytes(6));
		mkdir($this->root . '/src', 0777, true);
		mkdir($this->root . '/tmp/extract', 0777, true);
		mkdir($this->root . '/apps', 0777, true);
		$savedRoots = \OC::$APPSROOTS;
		$savedErrors = libxml_use_internal_errors(true);

		try {
			$this->archiveBytes = $this->buildArchive((string)$s['archive']);
			$this->prepareApps($s);
			\OC::$APPSROOTS = $this->appsRoots((string)$s['appsRoot']);
			InstallerGoldenSupport::useServices($this->serverServices($s));
			if ($s['sha'] === 'none') {
				$s['release'] = ['download' => self::DOWNLOAD];
			}
			$binding = $this->binding($s);
			$installer = $this->installer($s);
			if ($s['repeat'] === true) {
				$this->installTwiceFirst($installer, $s, $binding);
			}
			$warnings = [];
			set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
				$warnings[] = $message;

				return true;
			});

			try {
				$result = $installer->installFromExternalRelease(
					(string)$s['appId'],
					(string)$s['version'],
					(array)$s['release'],
					$binding,
					(bool)$s['dryRun'],
					(bool)$s['acceptNewSha'],
				);
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
			$outcome['calls'] = $this->calls;
			$outcome['apps'] = InstallerGoldenSupport::snapshot($this->root . '/apps');
			$outcome['tmp'] = $this->tmpSnapshot();

			return $this->mask($outcome);
		} finally {
			InstallerGoldenSupport::resetServices();
			\OC::$APPSROOTS = $savedRoots;
			libxml_clear_errors();
			libxml_use_internal_errors($savedErrors);
			@chmod($this->root . '/apps', 0777);
			InstallerGoldenSupport::removeTree($this->root);
		}
	}

	/**
	 * Runs the same install once as a dry run first, so the recorded run is
	 * the installer's second.
	 *
	 * @param array<string, mixed> $s
	 */
	private function installTwiceFirst(ExternalReleaseInstallerService $installer, array $s, SourceBinding $binding): void {
		try {
			$installer->installFromExternalRelease('someapp', (string)$s['version'], (array)$s['release'], $binding, true, false);
		} catch (Throwable) {
		}
		$this->calls = [];
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
			'empty-version' => ['someapp/appinfo/info.xml' => $info('someapp', '')],
			default => $good,
		};
		$path = $this->root . '/src/archive.' . ($shape === 'zip' ? 'zip' : 'tar.gz');
		if ($shape === 'junk') {
			file_put_contents($path, 'not an archive');
		} elseif ($shape === 'zip') {
			InstallerGoldenSupport::zip($path, $files);
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
	 * @return array<int, array{path: string, url: string, writable: bool}>
	 */
	private function appsRoots(string $shape): array {
		$apps = $this->root . '/apps';
		if ($shape === 'readonly') {
			chmod($apps, 0555);
		}

		return match ($shape) {
			'none' => [],
			'flag-false' => [['path' => $apps, 'url' => '/apps', 'writable' => false]],
			default => [['path' => $this->root . '/ro', 'url' => '/ro', 'writable' => false], ['path' => $apps, 'url' => '/apps', 'writable' => true]],
		};
	}

	/**
	 * @param array<string, mixed> $s
	 * @return array<string, object>
	 */
	private function serverServices(array $s): array {
		$l = $this->createMock(IL10N::class);
		$l->method('getLanguageCode')->willReturn('nl');
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

		return [IFactory::class => $factory, ServerVersion::class => $version, FilenameValidator::class => $validator];
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function binding(array $s): SourceBinding {
		$binding = match ($s['binding']) {
			'appstore' => SourceBinding::appStore(),
			'codeberg' => SourceBinding::codeberg('acme', 'someapp'),
			default => SourceBinding::github('acme', 'someapp'),
		};
		$binding = SourceBinding::fromArray(['boundAt' => '2026-01-01T00:00:00+00:00'] + $binding->toArray());
		$sha = match ($s['recorded']) {
			'match' => hash('sha256', $this->archiveBytes),
			'other' => self::OTHER_SHA,
			default => null,
		};

		return $sha === null ? $binding : $binding->withRecordedSha('2.0.0', $sha);
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function installer(array $s): ExternalReleaseInstallerService {
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (string $suffix) use ($s): string|false {
			$this->record('temp.file', [$suffix]);

			return $s['temp'] === true || $s['temp'] === 'no-folder' ? $this->root . '/tmp/archive' . $suffix : false;
		});
		$tempManager->method('getTemporaryFolder')->willReturnCallback(function (string $name) use ($s): string|false {
			$this->record('temp.folder', [$name]);

			return $s['temp'] === true || $s['temp'] === 'no-file' ? $this->root . '/tmp/extract' : false;
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(function (string $key, bool $default): bool {
			$this->record('config.bool', [$key, $default]);

			return false;
		});
		$config->method('getSystemValue')->willReturnCallback(function (string $key, mixed $default) use ($s): mixed {
			$this->record('config.value', [$key, $default]);

			return $s['overwrite'];
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default) use ($s): string {
			$this->record('appConfig.get', [$app, $key, $default]);

			return (string)$s['enabled'];
		});

		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->method('assertBindingAllowed')->willReturnCallback(function (SourceBinding $binding) use ($s): void {
			$this->record('trusted.assert', [$binding]);
			if ($s['trusted'] !== true) {
				throw new UntrustedSourceException($binding->getId());
			}
		});

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->record('logger.' . $level, [$message, $context]);
			});
		}

		$pat = new Pat();
		$pat->setId(7);
		$pat->setForge('github');
		$resolver = $this->createMock(PatResolver::class);
		$resolver->method('findFor')->willReturnCallback(function (string $forge, string $ownerRepo, ?string $uid) use ($s, $pat): ?Pat {
			$this->record('pat.findFor', [$forge, $ownerRepo, $uid]);

			return $s['pat'] === true ? $pat : null;
		});
		$patManager = $this->createMock(PatManager::class);
		$patManager->method('useToken')->willReturnCallback(function (Pat $pat, callable $callback): mixed {
			$this->record('pat.useToken', [$pat]);

			return $callback('forge-secret');
		});

		$userSession = $this->createMock(IUserSession::class);
		if ($s['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn((string)$s['user']);
			$userSession->method('getUser')->willReturn($user);
		}

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (mixed ...$args): void {
			$this->record('audit.record', $args);
		});

		$differ = $this->createMock(MigrationDiffer::class);
		$differ->method('diff')->willReturnCallback(function (?string $installed, ?string $target) use ($s): ?array {
			$this->record('differ.diff', [$installed, $target]);

			return $s['diff'];
		});

		$cache = $this->createMock(ArtifactCache::class);
		$cache->method('fetch')->willReturnCallback(function (string $appId, string $version) use ($s): ?array {
			$this->record('cache.fetch', [$appId, $version]);

			return $s['cache'] === null ? null : ['content' => $this->archiveBytes, 'meta' => []];
		});
		$cache->method('store')->willReturnCallback(function (string $appId, string $version, string $path, array $meta): void {
			$this->record('cache.store', [$appId, $version, $path, $meta, is_file($path) ? hash_file('sha256', $path) : 'missing']);
		});

		return new ExternalReleaseInstallerService(
			$this->clientService($s),
			$tempManager,
			$this->appManager($s),
			$config,
			$appConfig,
			$this->finalizer($s),
			$trusted,
			$logger,
			$resolver,
			$patManager,
			$userSession,
			$audit,
			$differ,
			$cache,
			new ForgeRegistry($this->createMock(IAppConfig::class)),
		);
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function clientService(array $s): IClientService {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url, array $options) use ($s): IResponse {
			$this->record('http.get', [$url, $options]);
			if (isset($options['sink'])) {
				if ($s['download'] === 'throw') {
					throw new Exception('Connection refused.');
				}
				file_put_contents((string)$options['sink'], $this->archiveBytes);

				return $this->createMock(IResponse::class);
			}

			return $this->checksumResponse((string)$s['sha']);
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}

	private function checksumResponse(string $shape): IResponse {
		$sha = hash('sha256', $this->archiveBytes);
		if ($shape === 'throw') {
			throw new Exception('Checksum host down.');
		}
		$body = match ($shape) {
			'mismatch' => self::OTHER_SHA,
			'bad' => 'not-a-digest',
			'named' => $sha . '  someapp.tar.gz' . "\n",
			'upper' => strtoupper($sha),
			default => $sha,
		};
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($shape === 'status404' ? 404 : 200);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	/**
	 * @param array<string, mixed> $s
	 */
	private function appManager(array $s): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturnCallback(function (string $appId) use ($s): string {
			$this->record('apps.getAppVersion', [$appId]);
			if ($s['installed'] === 'throw') {
				throw new Exception('not installed');
			}

			return (string)$s['installed'];
		});
		$appManager->method('getAppPath')->willReturnCallback(function (string $appId) use ($s): string {
			$this->record('apps.getAppPath', [$appId]);

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
		if ($value instanceof SourceBinding) {
			return ['binding' => $value->toArray()];
		}
		if ($value instanceof Pat) {
			return 'pat#' . $value->getId();
		}
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
		$json = str_replace([$this->root, hash('sha256', $this->archiveBytes), strtoupper(hash('sha256', $this->archiveBytes))], ['<root>', '<sha>', '<SHA>'], $json);

		/** @var array<string, mixed> */
		return json_decode($json, true);
	}
}
