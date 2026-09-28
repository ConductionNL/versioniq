<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

use Exception;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
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

final class InstallerServiceTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private IAppConfig&MockObject $appConfig;
	private SourceRegistry&MockObject $sourceRegistry;
	private SourceBindingStore&MockObject $bindingStore;
	private TrustedSourceList&MockObject $trustedSources;
	private SelectedReleaseInstallerService&MockObject $signedInstaller;
	private ExternalReleaseInstallerService&MockObject $externalInstaller;
	private EnvironmentCheck&MockObject $environmentCheck;
	private PinStore&MockObject $pinStore;
	private IUserSession&MockObject $userSession;
	private ITimeFactory&MockObject $timeFactory;
	private LkgStore&MockObject $lkgStore;
	private ArtifactCache&MockObject $artifactCache;
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
		$this->pinStore = $this->createMock(PinStore::class);
		$this->lkgStore = $this->createMock(LkgStore::class);
		$this->artifactCache = $this->createMock(ArtifactCache::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-24T00:00:00+00:00'));

		$l = $this->createMock(IL10N::class);
		// Faithful to OC\L10N\L10NString: vsprintf against the parameter array.
		$l->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters),
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$this->failureClassifier = new FailureClassifier($factory);

		// Common happy-path stubs: not core, fresh-ish install, trusted binding, no pin.
		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->bindingStore->method('get')->willReturn(null);
		// PinStore::get() is intentionally left unstubbed here — its ?Pin return
		// type makes PHPUnit's mock default to null, and stubbing it here would
		// take priority over a per-test ->method('get')->willReturn(...) override
		// (PHPUnit matches stubs in registration order for unconstrained calls).
		$this->config->method('getSystemValueBool')->willReturn(true); // maintenance already on
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
			$this->pinStore,
			$this->userSession,
			$this->timeFactory,
			$this->lkgStore,
			$this->artifactCache,
			$this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		);
	}

	private function stubSignedSourceReturning(): SourceInterface&MockObject {
		$source = $this->createMock(SourceInterface::class);
		$source->method('getInstallerKind')->willReturn(SourceInterface::INSTALLER_SIGNED);
		$source->method('resolveRelease')->willReturn(['download' => 'https://example/app.tar.gz', 'version' => '2.0.0']);
		$this->sourceRegistry->method('get')->willReturn($source);
		$this->signedInstaller->method('getDebugLog')->willReturn([]);

		return $source;
	}

	public function testPreflightGuardAbortsBeforeDownloadOnNonWritableDestination(): void {
		$this->appManager->method('getAppPath')->willReturn('/var/www/html/apps-extra/pipelinq');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(false);

		// The guard must fire before any release resolution / download.
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('pipelinq', '2.0.0', false);

		self::assertSame(Http::STATUS_CONFLICT, $result['statusCode']);
		self::assertNotSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION, $result['payload']['category']);
		self::assertSame(FailureClassifier::STAGE_REQUESTED, $result['payload']['stage']);
		self::assertNotSame('', $result['payload']['hint']);
	}

	public function testStructuredFailureFieldsPresentWithDebugOff(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(new Exception('Could not download selected release: timeout.'));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_BAD_GATEWAY, $result['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_DOWNLOAD, $result['payload']['category']);
		self::assertArrayHasKey('stage', $result['payload']);
		self::assertArrayHasKey('hint', $result['payload']);
		self::assertNotSame('', $result['payload']['hint']);
		// Debug was OFF, so no debug breadcrumb array is attached…
		self::assertArrayNotHasKey('debug', $result['payload']);
		// …but the structured fields are still present.
		self::assertSame('failed', $result['payload']['installStatus']);
	}

	public function testFinalizeFailureReportsInstalledButBroken(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::finalizeFailed('migration step failed', FailureClassifier::RESTORE_CLEAN));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN, $result['payload']['installStatus']);
		self::assertSame(FailureClassifier::CATEGORY_FINALIZE, $result['payload']['category']);
		self::assertStringContainsString('database', strtolower((string)$result['payload']['hint']));
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result['statusCode']);
	}

	public function testFinalizeFailureWithFailedRestoreUsesIndeterminateHint(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::finalizeFailed('migration step failed', FailureClassifier::RESTORE_FAILED));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN, $result['payload']['installStatus']);
		self::assertStringContainsString('indeterminate', strtolower((string)$result['payload']['hint']));
	}

	public function testFreshInstallFinalizeFailureDoesNotClaimLostPreviousFiles(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		// No prior version existed (RESTORE_NONE) — the hint must not tell the
		// admin that previous files could not be restored.
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::finalizeFailed('migration step failed', FailureClassifier::RESTORE_NONE));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN, $result['payload']['installStatus']);
		$hint = strtolower((string)$result['payload']['hint']);
		self::assertStringNotContainsString('indeterminate', $hint);
		self::assertStringNotContainsString('previous app files could not', $hint);
		self::assertStringContainsString('fresh install', $hint);
	}

	public function testPreFinalizeFailureReportsReverted(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(InstallFailure::reverted('Could not copy app file "x".', 'copy'));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(InstallFailure::OUTCOME_REVERTED, $result['payload']['installStatus']);
		self::assertNotSame('', $result['payload']['hint']);
	}

	// --- Pin guard matrix (see "Pins are enforced on Versioniq's own install path") ---

	private function stubSuccessfulSignedInstall(string $resultingVersion): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')->willReturn(['status' => 'installed']);
		$this->appManager->method('getAppInfoByPath')->willReturn(['version' => $resultingVersion]);
	}

	public function testInstallOverPinnedAppWithoutOverrideReturns409NamingPinnedVersion(): void {
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'alice', '2026-01-01T00:00:00+00:00'));
		// No download or filesystem change must happen — the guard fires first.
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_CONFLICT, $result['statusCode']);
		self::assertStringContainsString('1.0.0', $result['payload']['message']);
		self::assertSame('1.0.0', $result['payload']['pinnedVersion']);
	}

	public function testInvalidOverridePinValueReturns400(): void {
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, 'bogus');

		self::assertSame(Http::STATUS_BAD_REQUEST, $result['statusCode']);
	}

	public function testOverridePinRepinMovesThePinToTheNewlyInstalledVersion(): void {
		$oldPin = new Pin('1.0.0', 'previous-admin', '2026-01-01T00:00:00+00:00', 'reason-x');
		$this->pinStore->method('get')->willReturn($oldPin);
		$this->pinStore->expects(self::once())
			->method('set')
			->with(
				'someapp',
				self::callback(static fn (Pin $pin): bool => $pin->version === '2.0.0' && $pin->pinnedBy === 'admin' && $pin->reason === 'reason-x')
			);
		$this->pinStore->expects(self::never())->method('clear');
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, InstallerService::OVERRIDE_PIN_REPIN);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testOverridePinUnpinClearsThePinAfterSuccessfulInstall(): void {
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'alice', '2026-01-01T00:00:00+00:00'));
		$this->pinStore->expects(self::once())->method('clear')->with('someapp', 'admin');
		$this->pinStore->expects(self::never())->method('set');
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, InstallerService::OVERRIDE_PIN_UNPIN);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testOverridePinIsIgnoredWhenInstallFails(): void {
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'alice', '2026-01-01T00:00:00+00:00'));
		$this->pinStore->expects(self::never())->method('set');
		$this->pinStore->expects(self::never())->method('clear');
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(new Exception('Could not download selected release: timeout.'));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, InstallerService::OVERRIDE_PIN_REPIN);

		self::assertNotSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testReinstallingThePinnedVersionNeedsNoOverride(): void {
		// Pinned and already installed at the same version: proceeds via the
		// "already has this version installed" short-circuit, and the pin is
		// left untouched.
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'alice', '2026-01-01T00:00:00+00:00'));
		$this->pinStore->expects(self::never())->method('set');
		$this->pinStore->expects(self::never())->method('clear');

		$result = $this->service()->installAppVersion('someapp', '1.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertSame('App already has this version installed.', $result['payload']['message']);
	}

	public function testInstallThenPinAtomicallyPinsOnSuccessWhenNotAlreadyPinned(): void {
		$this->pinStore->expects(self::once())
			->method('set')
			->with(
				'someapp',
				self::callback(static fn (Pin $pin): bool => $pin->version === '2.0.0' && $pin->pinnedBy === 'admin' && $pin->reason === null)
			);
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, null, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testReinstallingThePinnedVersionAfterDriftClearsTheDriftMarkers(): void {
		$driftedPin = (new Pin('2.0.0', 'alice', '2026-01-01T00:00:00+00:00'))->withDrift('3.0.0', '2026-01-02T00:00:00+00:00');
		$this->pinStore->method('get')->willReturn($driftedPin);
		$this->pinStore->expects(self::once())
			->method('set')
			->with(
				'someapp',
				self::callback(static fn (Pin $pin): bool => $pin->version === '2.0.0' && !$pin->hasDrifted())
			);
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testReinstallingAnUndriftedPinnedVersionDoesNotTouchTheStore(): void {
		$this->pinStore->method('get')->willReturn(new Pin('2.0.0', 'alice', '2026-01-01T00:00:00+00:00'));
		$this->pinStore->expects(self::never())->method('set');
		$this->pinStore->expects(self::never())->method('clear');
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testPinRequestedDoesNotWriteAPinWhenInstallFails(): void {
		$this->pinStore->expects(self::never())->method('set');
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')
			->willThrowException(new Exception('Could not download selected release: timeout.'));

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, null, true);

		self::assertNotSame(Http::STATUS_OK, $result['statusCode']);
	}

	// --- Server-side downgrade guard matrix (see "Server-side downgrade guard") ---
	// Fixture default: installed version is '1.0.0' (see setUp()).

	public function testDowngradeWithoutAcknowledgementIsRefusedBeforeAnyDownload(): void {
		// No download or filesystem change must happen — the guard fires first.
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('someapp', '0.9.0', false);

		self::assertSame(Http::STATUS_CONFLICT, $result['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_DOWNGRADE_GUARD, $result['payload']['category']);
		self::assertStringContainsString('1.0.0', $result['payload']['hint']);
		self::assertStringContainsString('0.9.0', $result['payload']['hint']);
		self::assertSame('1.0.0', $result['payload']['fromVersion']);
		self::assertSame('0.9.0', $result['payload']['toVersion']);
	}

	public function testAcknowledgedDowngradeProceedsThroughTheNormalFlow(): void {
		$this->stubSuccessfulSignedInstall('0.9.0');

		$result = $this->service()->installAppVersion('someapp', '0.9.0', false, null, null, false, false, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testUpgradesAreUnaffectedByTheDowngradeGuard(): void {
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
	}

	public function testEqualVersionReinstallIsUnaffectedByTheDowngradeGuard(): void {
		// version_compare('1.0.0', '1.0.0', '<') is false — the "already
		// installed" short-circuit handles this, not the downgrade guard.
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('someapp', '1.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertSame('App already has this version installed.', $result['payload']['message']);
	}

	public function testDryRunDowngradeEvaluatesAndReportsTheGuardWithoutRequiringTheFlag(): void {
		$this->stubSuccessfulSignedInstall('0.9.0');

		// includeDebug=true is this app's dry-run signal; allowDowngrade is
		// intentionally omitted — the guard must not block a dry run.
		$result = $this->service()->installAppVersion('someapp', '0.9.0', true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['payload']['dryRun']);
		self::assertSame('1.0.0', $result['payload']['fromVersion']);
		self::assertSame('0.9.0', $result['payload']['toVersion']);
	}

	public function testOrphanedMigrationsIsPropagatedFromTheInstallerResultOnDowngrade(): void {
		$this->appManager->method('getAppPath')->willReturn('/writable/app');
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->stubSignedSourceReturning();
		$this->signedInstaller->method('installFromSelectedRelease')->willReturn([
			'status' => 'installed',
			'orphanedMigrations' => ['Version2040Date20260101000000'],
		]);
		$this->appManager->method('getAppInfoByPath')->willReturn(['version' => '0.9.0']);

		$result = $this->service()->installAppVersion('someapp', '0.9.0', false, null, null, false, false, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertSame(['Version2040Date20260101000000'], $result['payload']['orphanedMigrations']);
	}

	public function testOrphanedMigrationsIsAbsentOnAnUpgrade(): void {
		$this->stubSuccessfulSignedInstall('2.0.0');

		$result = $this->service()->installAppVersion('someapp', '2.0.0', false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertArrayNotHasKey('orphanedMigrations', $result['payload']);
	}

	// --- Shared manageability predicate (see "CLI trust context") ---

	public function testIsManageableAppIsFalseForVersioniqItself(): void {
		self::assertFalse($this->service()->isManageableApp(Application::APP_ID));
	}

	public function testIsManageableAppIsFalseForAnAlwaysEnabledApp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAlwaysEnabledApps')->willReturn(['dav']);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');

		self::assertFalse($this->service()->isManageableApp('dav'));
	}

	public function testIsManageableAppIsTrueForAnOrdinaryInstalledApp(): void {
		self::assertTrue($this->service()->isManageableApp('someapp'));
	}

	public function testGetAppVersionsRefusesSelfManagementWithoutTouchingTheSource(): void {
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->getAppVersions(Application::APP_ID);

		self::assertSame(Http::STATUS_FORBIDDEN, $result['statusCode']);
		self::assertTrue($result['hasError']);
		self::assertStringContainsString('cannot be managed', $result['error']);
	}

	public function testGetAppVersionsRefusesACoreProtectedAppWithADistinctMessage(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAlwaysEnabledApps')->willReturn(['dav']);
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->getAppVersions('dav');

		self::assertSame(Http::STATUS_FORBIDDEN, $result['statusCode']);
		self::assertStringContainsString('core app', $result['error']);
	}

	public function testInstallAppVersionRefusesSelfManagementWithoutTouchingTheSource(): void {
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion(Application::APP_ID, '1.0.0', false);

		self::assertSame(Http::STATUS_FORBIDDEN, $result['statusCode']);
		self::assertStringContainsString('cannot be installed or updated', $result['payload']['message']);
	}

	public function testInstallAppVersionRefusesACoreProtectedAppWithADistinctMessage(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getAlwaysEnabledApps')->willReturn(['dav']);
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('dav', '1.0.0', false);

		self::assertSame(Http::STATUS_FORBIDDEN, $result['statusCode']);
		self::assertStringContainsString('core app', $result['payload']['message']);
	}

	// --- dryRun decoupled from debug/verbosity (see MODIFIED "Debug Mode") ---

	public function testExplicitDryRunFalseWithDebugTruePerformsARealInstallWithDebugTimeline(): void {
		$this->stubSuccessfulSignedInstall('2.0.0');
		$this->signedInstaller->method('getDebugLog')->willReturn([['stage' => 'finalize', 'data' => []]]);

		// debug=1 (includeDebug=true) but dryRun explicitly false: a real
		// install must happen, and the debug timeline is still attached.
		$result = $this->service()->installAppVersion('someapp', '2.0.0', true, null, null, false, false, false, false);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertFalse($result['payload']['dryRun']);
		self::assertArrayHasKey('debug', $result['payload']);
	}

	public function testExplicitDryRunTrueWithDebugFalseEvaluatesWithoutADebugTimeline(): void {
		$this->stubSuccessfulSignedInstall('2.0.0');

		// dryRun=1 explicitly, no debug: dry-run path, no debug timeline attached.
		$result = $this->service()->installAppVersion('someapp', '2.0.0', false, null, null, false, false, false, true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['payload']['dryRun']);
		self::assertArrayNotHasKey('debug', $result['payload']);
	}

	public function testOmittingDryRunFallsBackToLegacyDebugImpliesDryRunBehavior(): void {
		$this->stubSuccessfulSignedInstall('2.0.0');

		// $dryRun omitted (defaults to null) — legacy behavior: debug=1 alone
		// still implies a dry run.
		$result = $this->service()->installAppVersion('someapp', '2.0.0', true);

		self::assertSame(Http::STATUS_OK, $result['statusCode']);
		self::assertTrue($result['payload']['dryRun']);
	}

	public function testExplicitDryRunFalseWithDebugTrueDoesNotBypassTheDowngradeGuard(): void {
		// Regression guard: before the decoupling, the downgrade guard checked
		// !$includeDebug, so debug=1 alone would have silently bypassed it for
		// a REAL downgrade. It must now check !$dryRun instead.
		$this->sourceRegistry->expects(self::never())->method('get');

		$result = $this->service()->installAppVersion('someapp', '0.9.0', true, null, null, false, false, false, false);

		self::assertSame(Http::STATUS_CONFLICT, $result['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_DOWNGRADE_GUARD, $result['payload']['category']);
	}
}
