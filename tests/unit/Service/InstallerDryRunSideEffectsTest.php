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

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\ArchiveCheck;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\ExternalInstall;
use OCA\Versioniq\Service\Installer\ExternalInstallOptions;
use OCA\Versioniq\Service\Installer\ExternalInstallRequest;
use OCA\Versioniq\Service\Installer\ExternalInstallRun;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\InstallSteps;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\MigrationPreview;
use OCA\Versioniq\Service\Installer\ReleaseTarget;
use OCA\Versioniq\Service\Installer\SignedInstall;
use OCA\Versioniq\Service\Installer\SignedRelease;
use OCA\Versioniq\Service\Installer\SignedReleaseRun;
use OCA\Versioniq\Service\Installer\StagedRelease;
use OCA\Versioniq\Service\Installer\TempArchive;
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
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A dry run must change nothing (#427). It used to switch the whole instance
 * into maintenance mode while the package downloaded, and, for App Store apps,
 * to rename the live app folder aside and back.
 *
 * An upgrade dry run carries the database changes the target will make; a
 * real install and a downgrade do not, and building it writes nothing.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
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
	private const PREVIEW = ['steps' => [], 'repairSteps' => ['OCA\\SomeApp\\Repair\\Seed'], 'estimated' => false];

	/**
	 * A preview that answers only for the expected target and records each call.
	 *
	 * @param list<array{0: string, 1: ?string, 2: ?string}> $calls
	 */
	private function preview(array &$calls): MigrationPreview {
		$preview = $this->createMock(MigrationPreview::class);
		$preview->method('build')->willReturnCallback(static function (string $appId, ?string $installed, ?string $target) use (&$calls): array {
			$calls[] = [$appId, $installed, $target];
			return self::PREVIEW;
		});

		return $preview;
	}

	/**
	 * The signed installer's dry-run envelope for a release over installed 2.0.0.
	 *
	 * @param list<array{0: string, 1: ?string, 2: ?string}> $calls
	 * @return array<string, mixed>
	 */
	private function signedEnvelope(string $version, bool $dryRun, array &$calls): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.0.0');
		InstallerGoldenSupport::useServices([IAppManager::class => $appManager]);
		$differ = $this->createMock(MigrationDiffer::class);
		$differ->method('diff')->willReturn([]);
		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$differ,
			$this->createMock(ArtifactCache::class),
			$this->preview($calls),
		);
		$run = new SignedReleaseRun('someapp', new SignedRelease('https://example/app.tar.gz', 'sig', 'cert', $version), new TempArchive('/tmp/a', '/tmp/f'));
		$target = new ReleaseTarget('/staged/someapp', '/apps/someapp', '/apps/someapp');

		try {
			(new ReflectionMethod($installer, 'diffDowngrade'))->invoke($installer, $run, $target);
			(new ReflectionMethod($installer, 'previewUpgrade'))->invoke($installer, $run, $target, $dryRun);
		} finally {
			InstallerGoldenSupport::resetServices();
		}
		$install = new SignedInstall('someapp', ['version' => $version], '2.0.0', 'yes');

		return (new ReflectionMethod($installer, 'resultEnvelope'))->invoke($installer, $install, null);
	}

	public function testASignedUpgradeDryRunCarriesTheMigrationPreview(): void {
		$calls = [];
		$envelope = $this->signedEnvelope('3.0.0', true, $calls);

		self::assertSame(self::PREVIEW, $envelope['migrationPreview']);
		self::assertSame([['someapp', '/apps/someapp', '/staged/someapp']], $calls);
		self::assertArrayNotHasKey('orphanedMigrations', $envelope);
	}

	public function testASignedRealInstallBuildsNoPreview(): void {
		$calls = [];
		$envelope = $this->signedEnvelope('3.0.0', false, $calls);

		self::assertArrayNotHasKey('migrationPreview', $envelope);
		self::assertSame([], $calls);
	}

	public function testASignedDowngradeDryRunBuildsNoPreview(): void {
		$calls = [];
		$envelope = $this->signedEnvelope('1.0.0', true, $calls);

		self::assertArrayNotHasKey('migrationPreview', $envelope);
		self::assertSame([], $envelope['orphanedMigrations']);
		self::assertSame([], $calls);
	}

	/**
	 * The external installer's envelope for a release over installed 2.0.0.
	 *
	 * @param list<array{0: string, 1: ?string, 2: ?string}> $calls
	 * @return array<string, mixed>
	 */
	private function externalEnvelope(string $version, bool $dryRun, array &$calls): array {
		$differ = $this->createMock(MigrationDiffer::class);
		$differ->method('diff')->willReturn(['Version3000Date20260101000000']);
		$steps = new InstallSteps(
			$this->createMock(TrustedSourceList::class),
			$differ,
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->preview($calls),
		);
		$installer = $this->buildByName(ExternalReleaseInstallerService::class, ['steps' => $steps]);
		$binding = \OCA\Versioniq\Service\Source\SourceBinding::github('owner', 'someapp');
		$run = new ExternalInstallRun(new ExternalInstallRequest('someapp', $version, [], new ExternalInstallOptions($dryRun, false)), $binding, '2.0.0');
		$staged = new StagedRelease('/staged/someapp', [], '/apps/someapp', '/apps/someapp');
		$orphaned = (new ReflectionMethod($installer, 'orphanedMigrations'))->invoke($installer, $run, $staged);
		$preview = (new ReflectionMethod($installer, 'migrationPreview'))->invoke($installer, $run, $staged);
		$install = new ExternalInstall($run, new ArchiveCheck('sha', true, null), $staged, $orphaned, $preview);

		return (new ReflectionMethod($installer, 'resultEnvelope'))->invoke($installer, $install, ['binding' => $binding]);
	}

	public function testAnExternalUpgradeDryRunCarriesTheMigrationPreview(): void {
		$calls = [];
		$envelope = $this->externalEnvelope('3.0.0', true, $calls);

		self::assertSame(self::PREVIEW, $envelope['migrationPreview']);
		self::assertSame([['someapp', '/apps/someapp', '/staged/someapp']], $calls);
		self::assertArrayNotHasKey('orphanedMigrations', $envelope);
	}

	public function testAnExternalRealInstallBuildsNoPreview(): void {
		$calls = [];
		$envelope = $this->externalEnvelope('3.0.0', false, $calls);

		self::assertArrayNotHasKey('migrationPreview', $envelope);
		self::assertSame([], $calls);
	}

	public function testAnExternalDowngradeDryRunBuildsNoPreview(): void {
		$calls = [];
		$envelope = $this->externalEnvelope('1.0.0', true, $calls);

		self::assertArrayNotHasKey('migrationPreview', $envelope);
		self::assertSame(['Version3000Date20260101000000'], $envelope['orphanedMigrations']);
		self::assertSame([], $calls);
	}

	public function testTheInstallerServicePassesThePreviewOn(): void {
		$service = $this->service();
		$fields = (new ReflectionMethod($service, 'optionalResultFields'))->invoke($service, ['migrationPreview' => self::PREVIEW]);

		self::assertSame(['migrationPreview' => self::PREVIEW], $fields);
	}
}
