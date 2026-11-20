<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\ForceOverrideStore;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Install anyway": the override is put in place around the install, recorded
 * after a success and removed after a failure when Versioniq added it; a dry
 * run changes no config (install-force-incompatible D2).
 */
final class InstallerForceIncompatibleTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<string> */
	private array $calls = [];

	private ForceOverrideStore&MockObject $overrides;

	private function service(bool $installerFails = false, bool $added = true): InstallerService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'version' ? '33.0.2.1' : $default);
		$config->method('setSystemValue')->willReturnCallback(function (string $key, mixed $value): void {
			$this->calls[] = 'system.' . $key . '=' . var_export($value, true);
		});

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAlwaysEnabledApps')->willReturn([]);
		// 1.3.0 is installed until the installer has run.
		$appManager->method('getAppVersion')->willReturnCallback(fn (): string => in_array('installer', $this->calls, true) ? '1.4.0' : '1.3.0');
		$appManager->method('getAppPath')->willReturn('/writable/hermiq');

		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/hermiq.tar.gz', 'version' => '1.4.0']);
		$registry = $this->createMock(SourceRegistry::class);
		$registry->method('get')->willReturn($source);

		$signed = $this->createMock(SelectedReleaseInstallerService::class);
		$signed->method('getDebugLog')->willReturn([]);
		$signed->method('installFromSelectedRelease')->willReturnCallback(function (string $appId, array $release, bool $dryRun) use ($installerFails): array {
			$this->calls[] = 'installer' . ($dryRun ? '(dry)' : '');
			if ($installerFails) {
				throw InstallFailure::reverted('Checksum mismatch.', FailureClassifier::STAGE_EXTRACTED);
			}

			return ['status' => $dryRun ? 'dry-run' : 'installed'];
		});

		$environment = $this->createMock(EnvironmentCheck::class);
		$environment->method('isDestinationWritable')->willReturn(true);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T08:00:00+00:00'));

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->overrides = $this->createMock(ForceOverrideStore::class);
		$this->overrides->method('apply')->willReturnCallback(function (string $appId) use ($added): bool {
			$this->calls[] = 'apply ' . $appId;

			return $added;
		});
		$this->overrides->method('remove')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'remove ' . $appId;
		});
		$this->overrides->method('record')->willReturnCallback(function (string $appId, string $version, int $major, string $actor, bool $wasAdded): void {
			$this->calls[] = sprintf('record %s %s on %d by %s added=%s', $appId, $version, $major, $actor, var_export($wasAdded, true));
		});

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
			'userSession' => $session,
			'timeFactory' => $time,
			'lkgStore' => $this->createMock(LkgStore::class),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'forceOverrides' => $this->overrides,
			'eventDispatcher' => $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		]);
	}

	private static function request(bool $force, bool $dryRun = false): InstallRequest {
		return new InstallRequest(
			new InstallTarget('hermiq', '1.4.0', null),
			new InstallOptions(false, $dryRun, false, true, $force),
			new PinChoice(null, false),
		);
	}

	public function testAForcedInstallAppliesTheOverrideFirstAndRecordsIt(): void {
		$result = $this->service()->installAppVersion(self::request(force: true));

		$this->assertSame(200, $result['statusCode']);
		$this->assertTrue($result['payload']['forceIncompatible']);
		$this->assertSame([
			"system.maintenance=true",
			'apply hermiq',
			'installer',
			'record hermiq 1.4.0 on 33 by alice added=true',
			"system.maintenance=false",
		], $this->calls);
	}

	public function testAFailedForcedInstallRemovesTheEntryItAdded(): void {
		$result = $this->service(installerFails: true)->installAppVersion(self::request(force: true));

		$this->assertNotSame(200, $result['statusCode']);
		$this->assertSame(['system.maintenance=true', 'apply hermiq', 'installer', 'remove hermiq', 'system.maintenance=false'], $this->calls);
	}

	public function testAFailedForcedInstallKeepsAnEntrySomeoneElseSet(): void {
		$this->service(installerFails: true, added: false)->installAppVersion(self::request(force: true));

		$this->assertNotContains('remove hermiq', $this->calls);
	}

	public function testAForcedDryRunChangesNoConfig(): void {
		$result = $this->service()->installAppVersion(self::request(force: true, dryRun: true));

		$this->assertSame(200, $result['statusCode']);
		$this->assertTrue($result['payload']['forceIncompatible']);
		$this->assertSame(['installer(dry)'], $this->calls);
	}

	public function testAnInstallWithoutTheFlagNeverTouchesTheList(): void {
		$result = $this->service()->installAppVersion(self::request(force: false));

		$this->assertArrayNotHasKey('forceIncompatible', $result['payload']);
		$this->assertSame(['system.maintenance=true', 'installer', 'system.maintenance=false'], $this->calls);
	}
}
