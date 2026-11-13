<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/InstallArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use DateTime;
use Exception;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use RuntimeException;

/**
 * Golden test for InstallerService::installAppVersion(): every guard, pin
 * choice, source, installer outcome and version read-back runs against
 * recording doubles, and the returned envelope plus every collaborator call
 * is compared, case by case as JSON text, with
 * fixtures/install-app-version.golden.json. Set RECORD_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\Versioniq\Service\InstallerService
 * @uses \OCA\Versioniq\Service\Installer\FailureClassifier
 * @uses \OCA\Versioniq\Service\Installer\InstallFailure
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallRun
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @uses \OCA\Versioniq\Service\Installer\ReleaseInstall
 * @uses \OCA\Versioniq\Service\Installer\ShaMismatchException
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 * @uses \OCA\Versioniq\Service\Source\SourceRegistry
 * @uses \OCA\Versioniq\Service\Source\UntrustedSourceException
 * @uses \OCA\Versioniq\Service\Installer\AppIdFormat
 * @uses \OCA\Versioniq\Service\Installer\InstallState
 * @uses \OCA\Versioniq\Service\Installer\ReleaseInstallers
 * @uses \OCA\Versioniq\Service\Installer\ServerAccess
 * @uses \OCA\Versioniq\Service\Settings\ConfigStores
 * @uses \OCA\Versioniq\Service\Source\SourceLookup
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 */
final class InstallerServiceInstallGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/install-app-version.golden.json';

	private const BASE = [
		'args' => ['someapp', '2.0.0', false, null, null, false, false, false, null],
		'alwaysEnabled' => ['core'],
		'stored' => null,
		'untrusted' => false,
		'installed' => '1.0.0',
		'after' => '2.0.0',
		'pin' => null,
		'appPath' => '/apps/someapp',
		'writable' => true,
		'kind' => SourceInterface::INSTALLER_SIGNED,
		'release' => ['download' => 'https://example.test/someapp.tar.gz', 'version' => '2.0.0'],
		'maintenance' => false,
		'installer' => ['status' => 'installed'],
		'debugLog' => [['stage' => 'download', 'data' => []], ['stage' => 'extract']],
		'appInfo' => ['version' => '2.0.0'],
		'configured' => null,
		'notify' => true,
		'actor' => 'admin',
	];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	private bool $installerRan = false;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function scenarios(): array {
		$list = [];
		$args = static fn (array $over): array => array_replace(self::BASE['args'], $over);

		// Request guards.
		$list['empty app id'] = ['args' => $args([0 => '  '])];
		$list['empty version'] = ['args' => $args([1 => ' '])];
		$list['trimmed ids'] = ['args' => $args([0 => ' someapp ', 1 => ' 2.0.0 '])];
		$list['self managed'] = ['args' => $args([0 => 'versioniq'])];
		$list['core app'] = ['args' => $args([0 => 'core'])];
		$list['path-like app id'] = ['args' => $args([0 => '../someapp'])];
		$list['app id walking into versioniq'] = ['args' => $args([0 => 'someapp/../versioniq'])];
		$list['app id with capitals'] = ['args' => $args([0 => 'SomeApp'])];
		$list['app id with an inner space'] = ['args' => $args([0 => ' some app '])];
		$list['bad override pin'] = ['args' => $args([4 => 'bogus'])];
		$list['bad override pin on core app'] = ['args' => $args([0 => 'core', 4 => 'bogus'])];

		// Source binding.
		foreach (['', '  ', 'appstore', 'github:acme/someapp', 'github:acme', 'nope:x', 'codeberg:acme/someapp'] as $override) {
			$list['override ' . json_encode($override)] = ['args' => $args([3 => $override])];
			$list['override ' . json_encode($override) . ', stored github'] = ['args' => $args([3 => $override]), 'stored' => 'github'];
			$list['override ' . json_encode($override) . ', untrusted'] = ['args' => $args([3 => $override]), 'stored' => 'github', 'untrusted' => true];
		}
		$list['stored github'] = ['stored' => 'github'];
		$list['stored github, untrusted'] = ['stored' => 'github', 'untrusted' => true];
		$list['stored appstore'] = ['stored' => 'appstore'];

		// Installed version and the downgrade guard.
		foreach (['', 'throw', '2.0.0', '3.0.0', '2.0.0-beta'] as $installed) {
			foreach ([null, false, true] as $dryRun) {
				foreach ([false, true] as $debug) {
					foreach ([false, true] as $allowDowngrade) {
						$list["installed {$installed}, dryRun " . json_encode($dryRun) . ', debug ' . json_encode($debug) . ', allowDowngrade ' . json_encode($allowDowngrade)] = [
							'args' => $args([2 => $debug, 7 => $allowDowngrade, 8 => $dryRun]),
							'installed' => $installed,
						];
					}
				}
			}
		}

		// Pins.
		$pins = [
			'none' => null,
			'same' => ['version' => '2.0.0'],
			'other' => ['version' => '1.5.0', 'reason' => 'hold'],
			'same drifted' => ['version' => '2.0.0', 'drift' => '2.1.0'],
			'same drifted with reason' => ['version' => '2.0.0', 'drift' => '1.9.0', 'reason' => 'audit'],
			'other drifted' => ['version' => '1.0.0', 'drift' => '1.1.0', 'reason' => 'hold'],
		];
		foreach ($pins as $pinName => $pin) {
			foreach ([null, 'repin', 'unpin'] as $overridePin) {
				foreach ([false, true] as $pinRequested) {
					foreach ([false, true] as $dryRun) {
						foreach (['1.0.0', '2.0.0', ''] as $installed) {
							$list["pin {$pinName}, override " . json_encode($overridePin) . ', pinRequested ' . json_encode($pinRequested) . ', dryRun ' . json_encode($dryRun) . ", installed {$installed}"] = [
								'args' => $args([4 => $overridePin, 5 => $pinRequested, 8 => $dryRun]),
								'pin' => $pin,
								'installed' => $installed,
							];
						}
					}
				}
			}
		}
		$list['pin other, debug'] = ['args' => $args([2 => true]), 'pin' => $pins['other']];
		$list['repin, no actor'] = ['args' => $args([4 => 'repin']), 'pin' => $pins['other'], 'actor' => null];
		$list['unpin, no actor'] = ['args' => $args([4 => 'unpin']), 'pin' => $pins['other'], 'actor' => null];
		$list['pin requested, no actor'] = ['args' => $args([5 => true]), 'actor' => null];
		$list['same version, debug'] = ['args' => $args([2 => true]), 'installed' => '2.0.0'];
		$list['same version 2.0, pin drifted'] = ['args' => $args([1 => '2.0']), 'installed' => '2.0.0', 'pin' => ['version' => '2.0', 'drift' => '2.1.0']];

		// Pre-flight.
		foreach ([false, true] as $debug) {
			$list['app path missing, debug ' . json_encode($debug)] = ['args' => $args([2 => $debug]), 'appPath' => 'throw'];
			$list['not writable, debug ' . json_encode($debug)] = ['args' => $args([2 => $debug]), 'writable' => false];
			$list['not writable, fresh install, debug ' . json_encode($debug)] = ['args' => $args([2 => $debug]), 'writable' => false, 'installed' => ''];
			$list['release missing, debug ' . json_encode($debug)] = ['args' => $args([2 => $debug]), 'release' => null];
			$list['release error, debug ' . json_encode($debug)] = ['args' => $args([2 => $debug]), 'release' => ['error' => 'Asset gone.']];
			$list['release error not a string, debug ' . json_encode($debug)] = ['args' => $args([2 => $debug]), 'release' => ['error' => 5, 'download' => 'x']];
		}

		// Installers, outcomes and maintenance mode.
		$externalResult = [
			'status' => 'installed',
			'integrityWarning' => 'No checksum.',
			'recordedShaMatched' => true,
			'servedFromCache' => 1,
			'orphanedMigrations' => ['Version2Date'],
			'debug' => [['stage' => 'verify']],
		];
		$outcomes = [
			'plain' => ['status' => 'installed'],
			'empty' => [],
			'orphans' => ['status' => 'installed', 'orphanedMigrations' => null],
			'external fields' => $externalResult,
			'sha false' => ['status' => 'installed', 'recordedShaMatched' => false, 'integrityWarning' => null, 'debug' => 'flat'],
			'sha mismatch' => 'sha',
			'reverted' => 'reverted',
			'broken' => 'broken',
			'broken, no restore' => 'broken-none',
			'untrusted' => 'untrusted',
			'exception' => 'exception',
		];
		foreach ([SourceInterface::INSTALLER_SIGNED, 'external'] as $kind) {
			foreach ($outcomes as $outcomeName => $outcome) {
				foreach ([false, true] as $debug) {
					foreach ([false, true] as $maintenance) {
						$list["{$kind} {$outcomeName}, debug " . json_encode($debug) . ', maintenance ' . json_encode($maintenance)] = [
							'args' => $args([2 => $debug, 6 => $debug]),
							'kind' => $kind,
							'installer' => $outcome,
							'maintenance' => $maintenance,
							'stored' => $kind === 'external' ? 'github' : null,
						];
					}
				}
				$list["{$kind} {$outcomeName}, dry run"] = [
					'args' => $args([8 => true]),
					'kind' => $kind,
					'installer' => $outcome,
					'stored' => $kind === 'external' ? 'github' : null,
				];
			}
		}
		$list['exception, empty debug log'] = ['installer' => 'exception', 'debugLog' => []];
		$list['exception, debug log without stage'] = ['installer' => 'exception', 'debugLog' => [['stage' => 5]]];
		$list['exception, debug log not an array'] = ['installer' => 'exception', 'debugLog' => ['flat']];
		foreach ([FailureClassifier::STAGE_REQUESTED, FailureClassifier::STAGE_DOWNLOADED, FailureClassifier::STAGE_CHECKSUM, FailureClassifier::STAGE_FINALIZE] as $stage) {
			$list['exception, last stage ' . $stage] = ['installer' => 'exception', 'debugLog' => [['stage' => 'x'], ['stage' => $stage]]];
		}

		// Version read-back.
		$readBack = [
			'app info throws' => ['appInfo' => 'throw'],
			'app info null' => ['appInfo' => null],
			'app info without version' => ['appInfo' => ['id' => 'someapp']],
			'app info version not a string' => ['appInfo' => ['version' => 2]],
			'configured version' => ['configured' => '2.0.1'],
			'configured empty' => ['configured' => ''],
			'app info throws, configured empty' => ['appInfo' => 'throw', 'configured' => ''],
			'version unchanged' => ['appInfo' => ['version' => '1.0.0']],
			'version lower' => ['appInfo' => ['version' => '0.9.0']],
			'version empty' => ['appInfo' => 'throw', 'configured' => '', 'after' => ''],
		];
		foreach ($readBack as $name => $over) {
			$list[$name] = $over;
			$list[$name . ', fresh install'] = $over + ['installed' => ''];
			$list[$name . ', dry run'] = $over + ['args' => $args([8 => true])];
		}

		// Update event.
		$list['notify off'] = ['notify' => false];
		$list['notify off, fresh install'] = ['notify' => false, 'installed' => ''];

		return $list;
	}

	/**
	 * @param array<string, mixed> $over
	 */
	private function runCase(array $over): string {
		$this->calls = [];
		$this->installerRan = false;
		$case = array_replace(self::BASE, $over);
		try {
			$result = InstallArgs::call($this->service($case), ...$case['args']);
		} catch (\Throwable $error) {
			$result = ['thrown' => get_class($error), 'message' => $error->getMessage()];
		}

		return json_encode(['result' => self::normalize($result), 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	private static function normalize(mixed $value): mixed {
		if (is_array($value)) {
			return array_map(self::normalize(...), $value);
		}
		if (!is_object($value)) {
			return $value;
		}
		$out = ['@class' => (new \ReflectionClass($value))->getShortName()];
		foreach ((new ReflectionObject($value))->getProperties() as $property) {
			if ($property->isStatic()) {
				continue;
			}
			if ($property->getName() === 'boundAt') {
				// Stamped with the wall clock when a binding is built.
				$out['boundAt'] = '(clock)';
				continue;
			}
			$out[$property->getName()] = $property->isInitialized($value) ? self::normalize($property->getValue($value)) : '(uninitialized)';
		}

		return $out;
	}

	private function log(string $method, array $args): void {
		$this->calls[] = [$method, self::normalize($args)];
	}

	private function binding(string $kind): SourceBinding {
		return $kind === 'github'
			? SourceBinding::github('acme', 'someapp')->withRecordedSha('1.0.0', str_repeat('a', 64))
			: SourceBinding::appStore();
	}

	private function pin(?array $pin): ?Pin {
		if ($pin === null) {
			return null;
		}
		$drift = $pin['drift'] ?? null;

		return new Pin($pin['version'], 'alice', '2026-01-01T00:00:00+00:00', $pin['reason'] ?? null, $drift, $drift === null ? null : '2026-02-01T00:00:00+00:00');
	}

	private function installerOutcome(array $case, array $args): array {
		$this->installerRan = true;
		$outcome = $case['installer'];
		if ($outcome === 'sha') {
			throw new ShaMismatchException('someapp', '2.0.0', str_repeat('a', 64), str_repeat('b', 64));
		}
		if ($outcome === 'reverted') {
			throw InstallFailure::reverted('Extraction failed.', FailureClassifier::STAGE_EXTRACTED);
		}
		if ($outcome === 'broken') {
			throw InstallFailure::finalizeFailed('Migration failed.', FailureClassifier::RESTORE_FAILED);
		}
		if ($outcome === 'broken-none') {
			throw InstallFailure::finalizeFailed('Migration failed.', FailureClassifier::RESTORE_NONE);
		}
		if ($outcome === 'untrusted') {
			throw new UntrustedSourceException('github:acme/someapp', 'revoked');
		}
		if ($outcome === 'exception') {
			throw new RuntimeException('Network down.');
		}
		if ($case['kind'] !== SourceInterface::INSTALLER_SIGNED) {
			$outcome['binding'] = $args[3]->withRecordedSha('2.0.0', str_repeat('c', 64));
		}

		return $outcome;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function service(array $case): InstallerService {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAlwaysEnabledApps')->willReturn($case['alwaysEnabled']);
		$appManager->method('clearAppsCache')->willReturnCallback(function (): void {
			$this->log('appManager.clearAppsCache', []);
		});
		$appManager->method('getAppVersion')->willReturnCallback(function () use ($case): string {
			$this->log('appManager.getAppVersion', func_get_args());
			if ($this->installerRan) {
				return $case['after'];
			}
			if ($case['installed'] === 'throw') {
				throw new Exception('not installed');
			}

			return $case['installed'];
		});
		$appManager->method('getAppPath')->willReturnCallback(function () use ($case): string {
			$this->log('appManager.getAppPath', func_get_args());
			if ($case['appPath'] === 'throw') {
				throw new Exception('no path');
			}

			return $case['appPath'];
		});
		$appManager->method('getAppInfoByPath')->willReturnCallback(function () use ($case): ?array {
			$this->log('appManager.getAppInfoByPath', func_get_args());
			if ($case['appInfo'] === 'throw') {
				throw new Exception('no info');
			}

			return $case['appInfo'];
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(function () use ($case): bool {
			$this->log('config.getSystemValueBool', func_get_args());

			return $case['maintenance'];
		});
		$config->method('setSystemValue')->willReturnCallback(function (): void {
			$this->log('config.setSystemValue', func_get_args());
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = '') use ($case): string {
			$this->log('appConfig.getValueString', func_get_args());

			return $case['configured'] ?? $default;
		});
		$appConfig->method('getValueBool')->willReturnCallback(function () use ($case): bool {
			$this->log('appConfig.getValueBool', func_get_args());

			return $case['notify'];
		});

		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->method('get')->willReturnCallback(function () use ($case): ?SourceBinding {
			$this->log('bindingStore.get', func_get_args());

			return $case['stored'] === null ? null : $this->binding($case['stored']);
		});
		$bindingStore->method('set')->willReturnCallback(function (): void {
			$this->log('bindingStore.set', func_get_args());
		});

		$trustedSources = $this->createMock(TrustedSourceList::class);
		$trustedSources->method('assertBindingAllowed')->willReturnCallback(function (SourceBinding $binding) use ($case): void {
			$this->log('trustedSources.assertBindingAllowed', func_get_args());
			if ($case['untrusted'] && $binding->kind !== SourceBinding::KIND_APPSTORE) {
				throw new UntrustedSourceException($binding->getId());
			}
		});

		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn($case['kind'] === SourceInterface::INSTALLER_SIGNED ? SourceInterface::INSTALLER_SIGNED : 'external');
		$source->method('resolveRelease')->willReturnCallback(function () use ($case): ?array {
			$this->log('source.resolveRelease', func_get_args());

			return $case['release'];
		});
		$sourceRegistry = $this->createMock(SourceRegistry::class);
		$sourceRegistry->method('get')->willReturnCallback(function () use ($source): SourceInterface {
			$this->log('sourceRegistry.get', func_get_args());

			return $source;
		});

		$signedInstaller = $this->createMock(SelectedReleaseInstallerService::class);
		$signedInstaller->method('installFromSelectedRelease')->willReturnCallback(function () use ($case): array {
			$this->log('signedInstaller.installFromSelectedRelease', func_get_args());

			return $this->installerOutcome($case, func_get_args());
		});
		$signedInstaller->method('getDebugLog')->willReturnCallback(function () use ($case): array {
			$this->log('signedInstaller.getDebugLog', []);

			return $case['debugLog'];
		});
		$externalInstaller = $this->createMock(ExternalReleaseInstallerService::class);
		$externalInstaller->method('installFromExternalRelease')->willReturnCallback(function () use ($case): array {
			$this->log('externalInstaller.installFromExternalRelease', func_get_args());

			return $this->installerOutcome($case, func_get_args());
		});
		$externalInstaller->method('getDebugLog')->willReturnCallback(function () use ($case): array {
			$this->log('externalInstaller.getDebugLog', []);

			return $case['debugLog'];
		});

		$environmentCheck = $this->createMock(EnvironmentCheck::class);
		$environmentCheck->method('isDestinationWritable')->willReturnCallback(function () use ($case): bool {
			$this->log('environmentCheck.isDestinationWritable', func_get_args());

			return $case['writable'];
		});

		$pinStore = $this->createMock(PinStore::class);
		$pinStore->method('get')->willReturnCallback(function () use ($case): ?Pin {
			$this->log('pinStore.get', func_get_args());

			return $this->pin($case['pin']);
		});
		$pinStore->method('set')->willReturnCallback(function (): void {
			$this->log('pinStore.set', func_get_args());
		});
		$pinStore->method('clear')->willReturnCallback(function (): void {
			$this->log('pinStore.clear', func_get_args());
		});

		$userSession = $this->createMock(IUserSession::class);
		$user = null;
		if ($case['actor'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['actor']);
		}
		$userSession->method('getUser')->willReturn($user);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturnCallback(function (): DateTime {
			$this->log('timeFactory.getDateTime', func_get_args());

			return new DateTime('2026-07-24T00:00:00+00:00');
		});

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$eventDispatcher = $this->createMock(IEventDispatcher::class);
		$eventDispatcher->method('dispatchTyped')->willReturnCallback(function (): void {
			$this->log('eventDispatcher.dispatchTyped', func_get_args());
		});

		return $this->buildByName(InstallerService::class, [
			'appManager' => $appManager,
			'config' => $config,
			'appConfig' => $appConfig,
			'sourceRegistry' => $sourceRegistry,
			'bindingStore' => $bindingStore,
			'trustedSources' => $trustedSources,
			'signedInstaller' => $signedInstaller,
			'externalInstaller' => $externalInstaller,
			'failureClassifier' => new FailureClassifier($factory),
			'environmentCheck' => $environmentCheck,
			'pinStore' => $pinStore,
			'userSession' => $userSession,
			'timeFactory' => $timeFactory,
			'lkgStore' => $this->createMock(LkgStore::class),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'eventDispatcher' => $eventDispatcher,
		]);
	}

	/**
	 * An app id that is not a Nextcloud app id is refused before the
	 * self/core guard and before any path lookup.
	 */
	public function testAnAppIdThatIsNoAppIdIsRefusedBeforeAnyLookup(): void {
		foreach (['../someapp', 'someapp/../versioniq', '../../etc/passwd', 'SomeApp', 'some app', '1app'] as $appId) {
			$result = json_decode($this->runCase(['args' => array_replace(self::BASE['args'], [0 => $appId])]), true, 512, JSON_THROW_ON_ERROR);

			$this->assertSame(400, $result['result']['statusCode'], $appId);
			$this->assertSame(['appId' => $appId, 'message' => 'Not a valid app id.'], $result['result']['payload'], $appId);
			$this->assertSame([], $result['calls'], $appId);
			$this->assertFalse($this->installerRan, $appId);
		}
	}

	public function testInstallAppVersionMatchesTheGoldenFixture(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $over) {
			$actual[$name] = $this->runCase($over);
		}
		if (getenv('RECORD_GOLDEN') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		/** @var array<string, string> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($actual as $name => $json) {
			$this->assertSame($expected[$name], $json, $name);
		}
	}
}
