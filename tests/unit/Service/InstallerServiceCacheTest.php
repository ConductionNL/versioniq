<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
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
 * Covers "Cache visibility and management" (`cachedOffline` listing stamp)
 * and the `servedFromCache` outcome flag from "Cached fallback with full
 * re-verification".
 *
 * @spec openspec/specs/artifact-cache/spec.md
 */
final class InstallerServiceCacheTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private SourceRegistry&MockObject $sourceRegistry;
	private SourceBindingStore&MockObject $bindingStore;
	private TrustedSourceList&MockObject $trustedSources;
	private SelectedReleaseInstallerService&MockObject $signedInstaller;
	private ExternalReleaseInstallerService&MockObject $externalInstaller;
	private EnvironmentCheck&MockObject $environmentCheck;
	private ArtifactCache&MockObject $artifactCache;
	private FailureClassifier $failureClassifier;
	private IUserSession $userSession;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->sourceRegistry = $this->createMock(SourceRegistry::class);
		$this->bindingStore = $this->createMock(SourceBindingStore::class);
		$this->trustedSources = $this->createMock(TrustedSourceList::class);
		$this->signedInstaller = $this->createMock(SelectedReleaseInstallerService::class);
		$this->externalInstaller = $this->createMock(ExternalReleaseInstallerService::class);
		$this->environmentCheck = $this->createMock(EnvironmentCheck::class);
		$this->artifactCache = $this->createMock(ArtifactCache::class);

		$l = $this->createMock(IL10N::class);
		// Faithful to OC\L10N\L10NString: vsprintf against the parameter array.
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$this->failureClassifier = new FailureClassifier($factory);

		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->appManager->method('isShipped')->willReturn(false);
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->appManager->method('getAppInfoByPath')->willReturn(['version' => '2.5.0']);
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->bindingStore->method('get')->willReturn(SourceBinding::github('ConductionNL', 'openregister'));
		$this->config->method('getSystemValueBool')->willReturn(true); // maintenance already on

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$this->userSession = $userSession;
	}

	private function service(): InstallerService {
		return new InstallerService(
			$this->appManager,
			$this->config,
			$this->appConfig,
			$this->sourceRegistry,
			$this->bindingStore,
			$this->trustedSources,
			$this->signedInstaller,
			$this->externalInstaller,
			$this->failureClassifier,
			$this->environmentCheck,
			$this->createMock(PinStore::class),
			$this->userSession,
			$this->createMock(ITimeFactory::class),
			$this->createMock(\OCA\Versioniq\Service\Lkg\LkgStore::class),
			$this->artifactCache,
			$this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		);
	}

	private function stubSourceReturning(array $versions): void {
		$source = $this->createMock(SourceInterface::class);
		$source->method('listVersions')->willReturn(['versions' => $versions, 'error' => null]);
		$this->sourceRegistry->method('get')->willReturn($source);
	}

	public function testGetAppVersionsStampsCachedOfflineFromArtifactCache(): void {
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => null],
			['version' => '2.2.0', 'changelog' => null],
		]);
		$this->artifactCache->expects(self::once())
			->method('cachedVersionsFor')
			->with('openregister')
			->willReturn(['2.3.0']);

		$result = $this->service()->getAppVersions('openregister');

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['availableVersions'][0]['cachedOffline']);
		self::assertFalse($result['availableVersions'][1]['cachedOffline']);
	}

	/**
	 * #435: forge releases showed server compatibility as unknown; a cached
	 * one now carries it from its info.xml range. An uncached forge release
	 * stays unknown, and an App Store verdict is never overwritten.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function testCachedForgeVersionsGetServerCompatibilityFromTheirInfoXml(): void {
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => null],
			['version' => '2.2.0', 'changelog' => null],
			['version' => '2.1.0', 'changelog' => null],
			['version' => '2.0.0', 'changelog' => null, 'serverCompatible' => false],
		]);
		$this->config->method('getSystemValueString')->willReturnCallback(
			static fn (string $key, string $default = ''): string => $key === 'version' ? '31.0.4.1' : $default,
		);
		$this->artifactCache->method('cachedVersionsFor')->willReturn(['2.3.0', '2.2.0', '2.0.0']);
		$this->artifactCache->method('platformSpecFor')->willReturnMap([
			['openregister', '2.3.0', '>=30 <=31'],
			['openregister', '2.2.0', '>=32'],
			['openregister', '2.0.0', '>=30'],
		]);

		$versions = $this->service()->getAppVersions('openregister')['availableVersions'];

		self::assertTrue($versions[0]['serverCompatible']);
		self::assertFalse($versions[1]['serverCompatible']);
		self::assertNull($versions[2]['serverCompatible'] ?? null);
		self::assertFalse($versions[3]['serverCompatible']);
	}

	public function testGetAppVersionsMarksAllFalseWhenNothingCached(): void {
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => null],
		]);
		$this->artifactCache->method('cachedVersionsFor')->willReturn([]);

		$result = $this->service()->getAppVersions('openregister');

		self::assertFalse($result['availableVersions'][0]['cachedOffline']);
	}

	public function testInstallAppVersionSurfacesServedFromCacheTrue(): void {
		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_EXTERNAL);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.5.0']);
		$this->sourceRegistry->method('get')->willReturn($source);
		$this->externalInstaller->method('getDebugLog')->willReturn([]);

		$binding = SourceBinding::github('ConductionNL', 'openregister');
		$this->externalInstaller->method('installFromExternalRelease')->willReturn([
			'status' => 'installed',
			'installedVersionBefore' => '1.0.0',
			'installedApp' => 'openregister',
			'integrityWarning' => null,
			'dryRun' => false,
			'debug' => [],
			'binding' => $binding,
			'recordedShaMatched' => null,
			'servedFromCache' => true,
		]);

		$result = $this->service()->installAppVersion('openregister', '2.5.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['payload']['servedFromCache']);
	}

	public function testInstallAppVersionDefaultsServedFromCacheFalseWhenAbsent(): void {
		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.0.0']);
		$this->sourceRegistry->method('get')->willReturn($source);
		$this->signedInstaller->method('getDebugLog')->willReturn([]);
		$this->signedInstaller->method('installFromSelectedRelease')->willReturn([
			'status' => 'installed',
			'installedVersionBefore' => '1.0.0',
			'installedApp' => 'openregister',
			'dryRun' => false,
			'debug' => [],
		]);

		$result = $this->service()->installAppVersion('openregister', '2.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertFalse($result['payload']['servedFromCache']);
	}
}
