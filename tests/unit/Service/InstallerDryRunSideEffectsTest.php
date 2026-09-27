<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
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
 */
final class InstallerDryRunSideEffectsTest extends TestCase {
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

		return new InstallerService(
			$appManager,
			$config,
			$this->createMock(IAppConfig::class),
			$registry,
			$this->createMock(SourceBindingStore::class),
			$this->createMock(TrustedSourceList::class),
			$signed,
			$this->createMock(ExternalReleaseInstallerService::class),
			new FailureClassifier($factory),
			$environment,
			$this->createMock(PinStore::class),
			$this->createMock(IUserSession::class),
			$timeFactory,
			$this->createMock(LkgStore::class),
			$this->createMock(ArtifactCache::class),
		);
	}

	public function testDryRunNeverTakesMaintenanceMode(): void {
		$this->service()->installAppVersion('someapp', '1.0.0', false, dryRun: true);

		$maintenanceWrites = array_filter($this->systemWrites, static fn (array $w): bool => $w[0] === 'maintenance');
		self::assertSame([], array_values($maintenanceWrites), 'a dry run switched maintenance mode');
	}

	public function testRealRunStillTakesAndReleasesMaintenanceMode(): void {
		$this->service()->installAppVersion('someapp', '1.0.0', false, allowDowngrade: true, dryRun: false);

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
}
