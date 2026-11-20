<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/InstallArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Inventory\SbomArchive;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
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

/**
 * The SBOM copy after an install (inventory-export-sbom-metrics task 3.1): a
 * real install asks the archive for one, a dry run never does, and an archive
 * that throws leaves the install result exactly as it was.
 *
 * @covers \OCA\Versioniq\Service\InstallerService
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
 */
final class InstallerServiceSbomArchiveTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<array{0: string, 1: mixed}> */
	private array $systemWrites = [];

	private function service(SbomArchive $archive): InstallerService {
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
		$signed->method('installFromSelectedRelease')->willReturnCallback(static fn (string $appId, array $release, bool $dryRun): array => $dryRun ? ['status' => 'dry-run', 'dryRun' => true] : ['status' => 'installed']);
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
			'sbomArchive' => $archive,
		]);
	}

	public function testARealInstallKeepsAnSbomForTheApp(): void {
		$archive = $this->createMock(SbomArchive::class);
		$archive->expects(self::once())->method('writeFor')->with('someapp');

		$result = InstallArgs::call($this->service($archive), 'someapp', '1.0.0', false, allowDowngrade: true, dryRun: false);

		self::assertSame(200, $result['statusCode']);
	}

	public function testADryRunWritesNoSbom(): void {
		$archive = $this->createMock(SbomArchive::class);
		$archive->expects(self::never())->method('writeFor');

		InstallArgs::call($this->service($archive), 'someapp', '1.0.0', false, allowDowngrade: true, dryRun: true);
	}

	public function testAThrowingArchiveLeavesTheInstallResultUnchanged(): void {
		$quiet = $this->createMock(SbomArchive::class);
		$throwing = $this->createMock(SbomArchive::class);
		$throwing->method('writeFor')->willThrowException(new \RuntimeException('app data full'));

		$expected = InstallArgs::call($this->service($quiet), 'someapp', '1.0.0', false, allowDowngrade: true, dryRun: false);
		$actual = InstallArgs::call($this->service($throwing), 'someapp', '1.0.0', false, allowDowngrade: true, dryRun: false);

		self::assertSame($expected, $actual);
		self::assertSame(200, $actual['statusCode']);
	}
}
