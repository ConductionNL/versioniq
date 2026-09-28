<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

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
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers "Version listings carry release notes" (envelope truncation):
 *
 * @spec openspec/specs/changelog-visibility/spec.md
 */
final class InstallerServiceChangelogTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private SourceRegistry&MockObject $sourceRegistry;
	private SourceBindingStore&MockObject $bindingStore;
	private TrustedSourceList&MockObject $trustedSources;
	private SelectedReleaseInstallerService&MockObject $signedInstaller;
	private ExternalReleaseInstallerService&MockObject $externalInstaller;
	private EnvironmentCheck&MockObject $environmentCheck;
	private FailureClassifier $failureClassifier;

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
		$this->bindingStore->method('get')->willReturn(SourceBinding::github('ConductionNL', 'openregister'));
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
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(\OCA\Versioniq\Service\Lkg\LkgStore::class),
			$this->createMock(\OCA\Versioniq\Service\Cache\ArtifactCache::class),
			$this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		);
	}

	/**
	 * @param list<array{version:string, changelog?:?string}> $versions
	 */
	private function stubSourceReturning(array $versions): void {
		$source = $this->createMock(SourceInterface::class);
		$source->method('listVersions')->willReturn(['versions' => $versions, 'error' => null]);
		$this->sourceRegistry->method('get')->willReturn($source);
	}

	public function testChangelogPassesThroughUntouchedWhenWithinLimit(): void {
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => 'Fixes LDAP sync'],
		]);

		$result = $this->service()->getAppVersions('openregister');

		$this->assertSame(Http::STATUS_OK, $result['statusCode']);
		$this->assertSame('Fixes LDAP sync', $result['availableVersions'][0]['changelog']);
		$this->assertSame('Fixes LDAP sync', $result['versions'][0]['changelog']);
	}

	public function testNullChangelogStaysNull(): void {
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => null],
		]);

		$result = $this->service()->getAppVersions('openregister');

		$this->assertNull($result['availableVersions'][0]['changelog']);
	}

	public function testMissingChangelogKeyIsNullSafe(): void {
		// Older/foreign source implementations might omit the key entirely.
		$this->stubSourceReturning([
			['version' => '2.3.0'],
		]);

		$result = $this->service()->getAppVersions('openregister');

		$this->assertArrayHasKey('changelog', $result['availableVersions'][0]);
		$this->assertNull($result['availableVersions'][0]['changelog']);
	}

	public function testOversizedChangelogIsTruncatedWithMarker(): void {
		$oversized = str_repeat('a', 8192 + 500);
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => $oversized],
		]);

		$result = $this->service()->getAppVersions('openregister');

		$truncated = $result['availableVersions'][0]['changelog'];
		$this->assertIsString($truncated);
		$this->assertLessThanOrEqual(8192 + strlen(' …[truncated]'), strlen($truncated));
		$this->assertStringEndsWith(' …[truncated]', $truncated);
	}

	public function testChangelogExactlyAtLimitIsNotTruncated(): void {
		$exact = str_repeat('a', 8192);
		$this->stubSourceReturning([
			['version' => '2.3.0', 'changelog' => $exact],
		]);

		$result = $this->service()->getAppVersions('openregister');

		$this->assertSame($exact, $result['availableVersions'][0]['changelog']);
	}
}
