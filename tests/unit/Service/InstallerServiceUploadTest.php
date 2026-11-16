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
use OCA\Versioniq\Service\Installer\LocalArchive;
use OCA\Versioniq\Service\Installer\StoreSignatureCheck;
use OCA\Versioniq\Service\Installer\UploadedArchive;
use OCA\Versioniq\Service\Installer\UploadSupport;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * install-manual-upload task 2.1: installUploadedArchive() applies the guards
 * of a normal install, installs a store-signed upload through the signed
 * installer, and refuses an unsigned one unless the setting is on and the
 * SHA-256 was pasted or accepted. The source binding never changes.
 *
 * @covers \OCA\Versioniq\Service\InstallerService
 * @uses \OCA\Versioniq\Service\Installer\LocalArchive
 * @uses \OCA\Versioniq\Service\Installer\UploadSupport
 * @uses \OCA\Versioniq\Service\Installer\FailureClassifier
 * @uses \OCA\Versioniq\Service\Installer\InstallRequest
 * @uses \OCA\Versioniq\Service\Installer\InstallRun
 * @uses \OCA\Versioniq\Service\Installer\InstallTarget
 * @uses \OCA\Versioniq\Service\Installer\InstallOptions
 * @uses \OCA\Versioniq\Service\Installer\PinChoice
 * @uses \OCA\Versioniq\Service\Installer\AppIdFormat
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.1
 */
final class InstallerServiceUploadTest extends TestCase {
	use BuildsByParameterName;

	private const TOKEN = 'abcdefghij0123456789abcdefghij01';
	private const BYTES = 'the uploaded archive';

	private IAppManager&MockObject $appManager;
	private IConfig&MockObject $config;
	private SourceBindingStore&MockObject $bindingStore;
	private SelectedReleaseInstallerService&MockObject $signedInstaller;
	private ExternalReleaseInstallerService&MockObject $externalInstaller;
	private EnvironmentCheck&MockObject $environmentCheck;
	private PinStore&MockObject $pinStore;
	private UploadedArchive&MockObject $archives;
	private StoreSignatureCheck&MockObject $signatures;
	private InstanceSettings&MockObject $settings;
	private string $dir;
	private string $uploadVersion = '2.0.0';
	private string $storedBytes = self::BYTES;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/versioniq-upload-' . bin2hex(random_bytes(6));
		mkdir($this->dir);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->bindingStore = $this->createMock(SourceBindingStore::class);
		$this->signedInstaller = $this->createMock(SelectedReleaseInstallerService::class);
		$this->externalInstaller = $this->createMock(ExternalReleaseInstallerService::class);
		$this->environmentCheck = $this->createMock(EnvironmentCheck::class);
		$this->pinStore = $this->createMock(PinStore::class);
		$this->archives = $this->createMock(UploadedArchive::class);
		$this->signatures = $this->createMock(StoreSignatureCheck::class);
		$this->settings = $this->createMock(InstanceSettings::class);

		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getAppVersion')->willReturn('1.0.0');
		$this->appManager->method('getAppPath')->willReturn('/writable/myapp');
		$this->appManager->method('getAppInfoByPath')->willReturnCallback(fn (): array => ['version' => $this->uploadVersion]);
		$this->environmentCheck->method('isDestinationWritable')->willReturn(true);
		$this->config->method('getSystemValueBool')->willReturn(true);
		$this->signedInstaller->method('getDebugLog')->willReturn([]);
		$this->externalInstaller->method('getDebugLog')->willReturn([]);

		$this->archives->method('inspected')->willReturnCallback(fn (string $token): ?array => $token === self::TOKEN
			? ['appId' => 'myapp', 'version' => $this->uploadVersion, 'sha256' => hash('sha256', self::BYTES), 'created' => 1]
			: null);
		$this->archives->method('copyTo')->willReturnCallback(function (string $token, string $path): void {
			file_put_contents($path, $this->storedBytes);
		});
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->dir . '/*') ?: []);
		rmdir($this->dir);
		parent::tearDown();
	}

	public function testAStoreSignedUploadInstallsThroughTheSignedInstallerAndKeepsTheBinding(): void {
		$release = ['version' => '2.0.0', 'signature' => 's', 'certificate' => 'c'];
		$this->signatures->method('storeRelease')->willReturn($release);
		$this->signedInstaller->expects($this->once())->method('installLocalArchive')
			->with('myapp', $release, $this->callback(fn (LocalArchive $a): bool => $a->signed && $a->sha256 === hash('sha256', self::BYTES) && is_file($a->path)))
			->willReturn(['status' => 'installed']);
		$this->externalInstaller->expects($this->never())->method('installLocalArchive');
		$this->bindingStore->expects($this->never())->method('set');
		$this->archives->expects($this->once())->method('remove')->with(self::TOKEN);

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, null, false);

		self::assertSame(Http::STATUS_OK, $outcome['statusCode']);
		self::assertSame('upload', $outcome['payload']['sourceId']);
		self::assertTrue($outcome['payload']['signedByStore']);
		self::assertSame(hash('sha256', self::BYTES), $outcome['payload']['sha256']);
		self::assertSame('upgrade', $outcome['payload']['updateType']);
		self::assertSame('2.0.0', $outcome['payload']['installedVersion']);
		self::assertSame([], glob($this->dir . '/*'), 'the temporary copy is removed');
	}

	public function testAnUnsignedUploadIsRefusedWhileTheSettingIsOff(): void {
		$this->signatures->method('storeRelease')->willReturn(null);
		$this->settings->method('allowsUnsignedUpload')->willReturn(false);
		$this->externalInstaller->expects($this->never())->method('installLocalArchive');
		$this->signedInstaller->expects($this->never())->method('installLocalArchive');
		$this->archives->expects($this->never())->method('remove');

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, hash('sha256', self::BYTES), true);

		self::assertSame(Http::STATUS_FORBIDDEN, $outcome['statusCode']);
		self::assertStringContainsString('Allow unsigned uploads', $outcome['payload']['message']);
		self::assertSame('upload', $outcome['payload']['sourceId']);
	}

	public function testAWrongExpectedShaIsRefusedBeforeAnythingIsCopied(): void {
		$this->settings->method('allowsUnsignedUpload')->willReturn(true);
		$this->archives->expects($this->never())->method('copyTo');
		$this->externalInstaller->expects($this->never())->method('installLocalArchive');

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, str_repeat('0', 64), true);

		self::assertSame(Http::STATUS_CONFLICT, $outcome['statusCode']);
		self::assertSame('sha_mismatch', $outcome['payload']['code']);
		self::assertSame(str_repeat('0', 64), $outcome['payload']['expectedSha']);
		self::assertSame(hash('sha256', self::BYTES), $outcome['payload']['actualSha']);
	}

	public function testAnUnsignedUploadWithTheMatchingShaInstallsThroughTheExternalInstaller(): void {
		$this->signatures->method('storeRelease')->willReturn(null);
		$this->settings->method('allowsUnsignedUpload')->willReturn(true);
		$binding = SourceBinding::github('acme', 'myapp');
		$this->bindingStore->method('get')->willReturn($binding);
		$this->bindingStore->expects($this->never())->method('set');
		$this->externalInstaller->expects($this->once())->method('installLocalArchive')
			->with('myapp', '2.0.0', $this->callback(static fn (LocalArchive $a): bool => !$a->signed), $binding)
			->willReturn(['status' => 'installed', 'binding' => $binding]);

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, strtoupper(hash('sha256', self::BYTES)), false);

		self::assertSame(Http::STATUS_OK, $outcome['statusCode']);
		self::assertFalse($outcome['payload']['signedByStore']);
		self::assertSame('upload', $outcome['payload']['sourceId']);
	}

	public function testAnUnsignedUploadNeedsAPastedOrAcceptedSha(): void {
		$this->signatures->method('storeRelease')->willReturn(null);
		$this->settings->method('allowsUnsignedUpload')->willReturn(true);
		$this->externalInstaller->expects($this->never())->method('installLocalArchive');

		$refused = $this->service()->installUploadedArchive(self::TOKEN, '  ', false);
		self::assertSame(Http::STATUS_BAD_REQUEST, $refused['statusCode']);

		$this->externalInstaller = $this->createMock(ExternalReleaseInstallerService::class);
		$this->externalInstaller->expects($this->once())->method('installLocalArchive')->willReturn(['status' => 'installed']);
		self::assertSame(Http::STATUS_OK, $this->service()->installUploadedArchive(self::TOKEN, null, true)['statusCode']);
	}

	public function testAPinnedAppIsRefusedWith409(): void {
		$this->pinStore->method('get')->willReturn(new Pin('1.0.0', 'admin', '2026-01-01T00:00:00+00:00'));
		$this->archives->expects($this->never())->method('copyTo');
		$this->signedInstaller->expects($this->never())->method('installLocalArchive');

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, null, true);

		self::assertSame(Http::STATUS_CONFLICT, $outcome['statusCode']);
		self::assertSame('pinned', $outcome['payload']['category']);
		self::assertSame('upload', $outcome['payload']['sourceId']);
	}

	public function testADowngradeWithoutAcknowledgementIsRefusedWith409(): void {
		$this->uploadVersion = '0.9.0';
		$this->archives->expects($this->never())->method('copyTo');

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, null, true);

		self::assertSame(Http::STATUS_CONFLICT, $outcome['statusCode']);
		self::assertSame(FailureClassifier::CATEGORY_DOWNGRADE_GUARD, $outcome['payload']['category']);
	}

	public function testAnUnknownTokenIs404(): void {
		$outcome = $this->service()->installUploadedArchive('nope', null, true);

		self::assertSame(Http::STATUS_NOT_FOUND, $outcome['statusCode']);
	}

	public function testAnArchiveThatChangedAfterInspectionIsRefused(): void {
		$this->storedBytes = 'other bytes';
		$this->signatures->expects($this->never())->method('storeRelease');

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, null, true);

		self::assertSame(Http::STATUS_CONFLICT, $outcome['statusCode']);
		self::assertStringContainsString('changed after it was inspected', $outcome['payload']['message']);
	}

	public function testAFailedInstallIsReportedWithTheUploadSource(): void {
		$this->signatures->method('storeRelease')->willReturn(null);
		$this->settings->method('allowsUnsignedUpload')->willReturn(true);
		$this->externalInstaller->method('installLocalArchive')->willThrowException(new \Exception('Could not parse appinfo/info.xml.'));
		$this->archives->expects($this->once())->method('remove');

		$outcome = $this->service()->installUploadedArchive(self::TOKEN, null, true);

		self::assertGreaterThanOrEqual(400, $outcome['statusCode']);
		self::assertSame('Could not parse appinfo/info.xml.', $outcome['payload']['message']);
		self::assertSame('upload', $outcome['payload']['sourceId']);
		self::assertSame('failed', $outcome['payload']['installStatus']);
	}

	private function service(): InstallerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-09-28T00:00:00+00:00'));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(fn (string $suffix): string => $this->dir . '/' . bin2hex(random_bytes(4)) . $suffix);

		return $this->buildByName(InstallerService::class, [
			'appManager' => $this->appManager,
			'config' => $this->config,
			'appConfig' => $this->createMock(IAppConfig::class),
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $this->bindingStore,
			'trustedSources' => $this->createMock(TrustedSourceList::class),
			'signedInstaller' => $this->signedInstaller,
			'externalInstaller' => $this->externalInstaller,
			'failureClassifier' => new FailureClassifier($factory),
			'environmentCheck' => $this->environmentCheck,
			'pinStore' => $this->pinStore,
			'userSession' => $userSession,
			'timeFactory' => $timeFactory,
			'lkgStore' => $this->createMock(LkgStore::class),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'eventDispatcher' => $this->createMock(IEventDispatcher::class),
			'upload' => new UploadSupport($this->archives, $this->signatures, $this->settings, $tempManager),
		]);
	}
}
