<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/InstallArgs.php';

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
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
	use BuildsByParameterName;

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
		return $this->buildByName(InstallerService::class, [
			'appManager' => $this->appManager,
			'config' => $this->config,
			'appConfig' => $this->appConfig,
			'sourceRegistry' => $this->sourceRegistry,
			'bindingStore' => $this->bindingStore,
			'trustedSources' => $this->trustedSources,
			'signedInstaller' => $this->signedInstaller,
			'externalInstaller' => $this->externalInstaller,
			'failureClassifier' => $this->failureClassifier,
			'environmentCheck' => $this->environmentCheck,
			'pinStore' => $this->createMock(PinStore::class),
			'userSession' => $this->userSession,
			'timeFactory' => $this->createMock(ITimeFactory::class),
			'lkgStore' => $this->createMock(\OCA\Versioniq\Service\Lkg\LkgStore::class),
			'artifactCache' => $this->createMock(\OCA\Versioniq\Service\Cache\ArtifactCache::class),
			'eventDispatcher' => $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		]);
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

		$result = InstallArgs::call($this->service(), 'openregister', '2.5.0', false);

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

		$result = InstallArgs::call($this->service(), 'openregister', '2.5.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['payload']['recordedShaMatched']);
	}

	public function testShaMismatchIsReportedAs422WithMachineReadableCode(): void {
		$this->externalInstaller->method('installFromExternalRelease')
			->willThrowException(new ShaMismatchException('openregister', '2.3.0', self::SHA_A, self::SHA_B));

		$result = InstallArgs::call($this->service(), 'openregister', '2.3.0', false);

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

		InstallArgs::call($this->service(), 'openregister', '2.3.0', false);
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

		$result = InstallArgs::call($this->service(), 'openregister', '2.3.0', false, null, null, false, true);

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

		$result = InstallArgs::call($this->service(), 'openregister', '2.5.0', false, null, null, false, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertArrayNotHasKey('recordedShaMatched', $result['payload']);
	}

	/**
	 * A promotion's install request for openregister 2.5.0, carrying the earlier stage's digest.
	 */
	private static function promotion(string $expectedSha): InstallRequest {
		return new InstallRequest(
			new InstallTarget('openregister', '2.5.0', null),
			new InstallOptions(false, false, false, false, $expectedSha),
			new PinChoice(null, false),
		);
	}

	/**
	 * The binding store answers $binding for every app.
	 */
	private function bindingIs(SourceBinding $binding): void {
		$this->bindingStore = $this->createMock(SourceBindingStore::class);
		$this->bindingStore->method('get')->willReturn($binding);
	}

	/** @return array<string, mixed> an installed result echoing the binding the installer was given */
	private function installedWith(SourceBinding $binding): array {
		return [
			'status' => 'installed',
			'installedVersionBefore' => '1.0.0',
			'installedApp' => 'openregister',
			'integrityWarning' => null,
			'dryRun' => false,
			'debug' => [],
			'binding' => $binding,
			'recordedShaMatched' => true,
		];
	}

	public function testAMatchingExpectedDigestInstallsWithTheRecordedBinding(): void {
		$this->bindingIs($this->binding->withRecordedSha('2.5.0', self::SHA_A));
		$given = [];
		$this->externalInstaller->expects(self::once())->method('installFromExternalRelease')
			->willReturnCallback(function (string $appId, string $version, array $release, SourceBinding $binding) use (&$given): array {
				$given[] = $binding->getRecordedSha('2.5.0');
				return $this->installedWith($binding);
			});

		$result = $this->service()->installAppVersion(self::promotion(strtoupper(self::SHA_A)));

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertSame([self::SHA_A], $given);
	}

	public function testADifferentRecordedDigestIsRefusedBeforeAnyDownload(): void {
		$this->bindingIs($this->binding->withRecordedSha('2.5.0', self::SHA_B));
		$this->externalInstaller->expects(self::never())->method('installFromExternalRelease');
		$this->bindingStore->expects(self::never())->method('set');

		$result = $this->service()->installAppVersion(self::promotion(self::SHA_A));

		self::assertSame(Http::STATUS_CONFLICT, $result['statusCode']);
		self::assertSame('sha_differs', $result['payload']['code']);
		self::assertSame('The two instances installed different packages for 2.5.0.', $result['payload']['message']);
		self::assertSame(self::SHA_A, $result['payload']['expectedSha']);
		self::assertSame(self::SHA_B, $result['payload']['recordedSha']);
	}

	public function testWithoutARecordedDigestTheExpectedOneIsAddedAndEnforcedByTheInstaller(): void {
		$given = [];
		$this->externalInstaller->expects(self::once())->method('installFromExternalRelease')
			->willReturnCallback(function (string $appId, string $version, array $release, SourceBinding $binding, bool $dryRun, bool $acceptNewSha) use (&$given): array {
				$given[] = [$binding->getRecordedSha('2.5.0'), $acceptNewSha];
				throw new ShaMismatchException('openregister', '2.5.0', self::SHA_A, self::SHA_B);
			});
		$this->bindingStore->expects(self::never())->method('set');

		$result = $this->service()->installAppVersion(self::promotion(self::SHA_A));

		self::assertSame([[self::SHA_A, false]], $given);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $result['statusCode']);
		self::assertSame('sha_mismatch', $result['payload']['code']);
	}

	public function testAMalformedExpectedDigestIsRefused(): void {
		$this->externalInstaller->expects(self::never())->method('installFromExternalRelease');

		$result = $this->service()->installAppVersion(self::promotion('not-a-digest'));

		self::assertSame(Http::STATUS_BAD_REQUEST, $result['statusCode']);
		self::assertSame('Recorded SHA-256 must be a 64-character hex digest.', $result['payload']['message']);
	}
}
