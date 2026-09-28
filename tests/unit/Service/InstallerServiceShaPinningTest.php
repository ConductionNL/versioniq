<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
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
 * Covers "SHA-256 recorded on first successful external install" and
 * "Recorded SHA-256 enforced on reinstall" from InstallerService's dispatch
 * layer (binding persistence, response fields, sha_mismatch error shape).
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class InstallerServiceShaPinningTest extends TestCase {
	private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
	private SourceBinding $binding;
	private IUserSession&MockObject $userSession;

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

		$this->binding = SourceBinding::github('ConductionNL', 'openregister');

		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->appManager->method('getAppInfoByPath')->willReturn(['version' => '2.5.0']);
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->bindingStore->method('get')->willReturn($this->binding);
		$this->config->method('getSystemValueBool')->willReturn(true); // maintenance already on

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->userSession = $userSession;

		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_EXTERNAL);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.5.0']);
		$this->sourceRegistry->method('get')->willReturn($source);
		$this->externalInstaller->method('getDebugLog')->willReturn([]);
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
			$this->createMock(\OCA\Versioniq\Service\Cache\ArtifactCache::class),
			$this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		);
	}

	public function testSuccessfulExternalInstallPersistsTheUpdatedBindingReturnedByTheInstaller(): void {
		$updatedBinding = $this->binding->withRecordedSha('2.5.0', self::SHA_A);
		$this->externalInstaller->method('installFromExternalRelease')->willReturn([
			'status' => 'installed',
			'installedVersionBefore' => null,
			'installedApp' => 'openregister',
			'integrityWarning' => null,
			'dryRun' => false,
			'debug' => [],
			'binding' => $updatedBinding,
			'recordedShaMatched' => false,
		]);

		$this->bindingStore->expects(self::once())
			->method('set')
			->with('openregister', self::callback(
				static fn (SourceBinding $binding): bool => $binding->getRecordedSha('2.5.0') === self::SHA_A
			));

		$result = $this->service()->installAppVersion('openregister', '2.5.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testMatchingRecordedShaSurfacesInTheResponsePayload(): void {
		$this->externalInstaller->method('installFromExternalRelease')->willReturn([
			'status' => 'installed',
			'installedVersionBefore' => '2.3.0',
			'installedApp' => 'openregister',
			'integrityWarning' => null,
			'dryRun' => false,
			'debug' => [],
			'binding' => $this->binding,
			'recordedShaMatched' => true,
		]);

		$result = $this->service()->installAppVersion('openregister', '2.5.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['payload']['recordedShaMatched']);
	}

	public function testShaMismatchIsReportedAs422WithMachineReadableCode(): void {
		$this->externalInstaller->method('installFromExternalRelease')
			->willThrowException(new ShaMismatchException('openregister', '2.3.0', self::SHA_A, self::SHA_B));

		$result = $this->service()->installAppVersion('openregister', '2.3.0', false);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result['statusCode']);
		self::assertSame('sha_mismatch', $result['payload']['code']);
		self::assertSame(FailureClassifier::CATEGORY_SHA_MISMATCH, $result['payload']['category']);
		self::assertSame(self::SHA_A, $result['payload']['expectedSha']);
		self::assertSame(self::SHA_B, $result['payload']['actualSha']);
		self::assertSame('failed', $result['payload']['installStatus']);
		self::assertStringContainsString(self::SHA_A, $result['payload']['message']);
		self::assertStringContainsString(self::SHA_B, $result['payload']['message']);
	}

	public function testShaMismatchDoesNotPersistAnyBindingChange(): void {
		$this->externalInstaller->method('installFromExternalRelease')
			->willThrowException(new ShaMismatchException('openregister', '2.3.0', self::SHA_A, self::SHA_B));

		$this->bindingStore->expects(self::never())->method('set');

		$this->service()->installAppVersion('openregister', '2.3.0', false);
	}

	public function testAcceptNewShaIsForwardedToTheExternalInstaller(): void {
		$this->externalInstaller->expects(self::once())
			->method('installFromExternalRelease')
			->with('openregister', '2.3.0', self::anything(), self::anything(), false, true)
			->willReturn([
				'status' => 'installed',
				'installedVersionBefore' => '2.3.0',
				'installedApp' => 'openregister',
				'integrityWarning' => null,
				'dryRun' => false,
				'debug' => [],
				'binding' => $this->binding->withRecordedSha('2.3.0', self::SHA_B),
				'recordedShaMatched' => false,
			]);

		$result = $this->service()->installAppVersion('openregister', '2.3.0', false, null, null, false, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testSignedInstallNeverForwardsAcceptNewShaOrTouchesRecordedShaFields(): void {
		$signedSource = $this->createMock(SourceInterface::class);
		$signedSource->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$signedSource->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.5.0']);
		$this->sourceRegistry = $this->createMock(SourceRegistry::class);
		$this->sourceRegistry->method('get')->willReturn($signedSource);
		$this->signedInstaller->method('getDebugLog')->willReturn([]);
		$this->signedInstaller->method('installFromSelectedRelease')->willReturn(['status' => 'installed']);
		$this->externalInstaller->expects(self::never())->method('installFromExternalRelease');

		$result = $this->service()->installAppVersion('openregister', '2.5.0', false, null, null, false, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertArrayNotHasKey('recordedShaMatched', $result['payload']);
	}
}
