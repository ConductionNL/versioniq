<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Versioniq\Controller\UploadController;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\StoreSignatureCheck;
use OCA\Versioniq\Service\Installer\UploadedArchive;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * install-manual-upload tasks 1.1, 1.2 and 2.1: POST /api/upload/inspect and
 * POST /api/upload/{token}/install.
 *
 * @covers \OCA\Versioniq\Controller\UploadController
 * @uses \OCA\Versioniq\Controller\ChecksAdmin
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.1
 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.1
 */
final class UploadControllerTest extends TestCase {
	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var UploadedArchive&MockObject */
	private UploadedArchive $uploads;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var StoreSignatureCheck&MockObject */
	private StoreSignatureCheck $signatures;

	/** @var InstallerService&MockObject */
	private InstallerService $installer;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->uploads = $this->createMock(UploadedArchive::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->signatures = $this->createMock(StoreSignatureCheck::class);
		$this->installer = $this->createMock(InstallerService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->session->method('getUser')->willReturn($user);
	}

	public function testANonAdminIsRefusedAndNothingIsRead(): void {
		$this->groups->method('isAdmin')->with('alice')->willReturn(false);
		$this->request->expects($this->never())->method('getUploadedFile');
		$this->uploads->expects($this->never())->method('store');

		$response = $this->controller()->inspect();

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Forbidden'], $response->getData());
	}

	public function testWithoutASessionTheCallerIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->session = $session;
		$this->uploads->expects($this->never())->method('store');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->inspect()->getStatus());
	}

	public function testAMissingOrFailedUploadIs400(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->uploads->expects($this->never())->method('store');

		foreach ([null, ['error' => UPLOAD_ERR_PARTIAL, 'tmp_name' => '/tmp/x'], ['error' => UPLOAD_ERR_OK, 'tmp_name' => '']] as $file) {
			$request = $this->createMock(IRequest::class);
			$request->method('getUploadedFile')->with('archive')->willReturn($file);
			$this->request = $request;

			$response = $this->controller()->inspect();
			self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			self::assertSame(['message' => 'Upload a .tar.gz app archive in the field "archive".'], $response->getData());
		}
	}

	public function testAFileThatWasNotUploadedIs400(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/hostname']);
		$this->uploads->expects($this->never())->method('store');

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller(false)->inspect()->getStatus());
	}

	public function testARefusedArchiveIs422WithTheReason(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/phpUPLOAD']);
		$this->uploads->method('store')->with('/tmp/phpUPLOAD')->willThrowException(new InvalidArgumentException('The archive has no appinfo/info.xml.'));

		$response = $this->controller()->inspect();

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'The archive has no appinfo/info.xml.'], $response->getData());
	}

	public function testAnAdminGetsTheAppIdVersionShaAndToken(): void {
		$inspected = ['token' => 't', 'appId' => 'myapp', 'version' => '2.1.0', 'sha256' => str_repeat('a', 64)];
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/phpUPLOAD']);
		$this->uploads->expects($this->once())->method('store')->with('/tmp/phpUPLOAD')->willReturn($inspected);
		$this->signatures->expects($this->once())->method('storeRelease')->with($this->callback(fn (InstallTarget $t): bool => $t->appId === 'myapp' && $t->version === '2.1.0'), '/tmp/phpUPLOAD')->willReturn(null);

		$response = $this->controller()->inspect();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($inspected + ['signedByStore' => false], $response->getData());
	}

	/**
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-1.2
	 */
	public function testAnUploadTheStoreSignatureVerifiesIsReportedSigned(): void {
		$inspected = ['token' => 't', 'appId' => 'myapp', 'version' => '2.1.0', 'sha256' => str_repeat('a', 64)];
		$this->groups->method('isAdmin')->willReturn(true);
		$this->request->method('getUploadedFile')->willReturn(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/phpUPLOAD']);
		$this->uploads->method('store')->willReturn($inspected);
		$this->signatures->method('storeRelease')->willReturn(['version' => '2.1.0', 'signature' => 's', 'certificate' => 'c']);

		self::assertTrue($this->controller()->inspect()->getData()['signedByStore']);
	}

	public function testANonAdminCannotInstallAnUpload(): void {
		$this->groups->method('isAdmin')->with('alice')->willReturn(false);
		$this->installer->expects($this->never())->method('installUploadedArchive');

		$response = $this->controller()->install('t');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['message' => 'Forbidden'], $response->getData());
	}

	public function testInstallPassesTheChoicesOnAndAnswersWithTheInstallersStatus(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->installer->expects($this->once())->method('installUploadedArchive')
			->with('t', 'abc', true, true, 'repin')
			->willReturn(['statusCode' => Http::STATUS_CONFLICT, 'payload' => ['message' => 'Pinned.', 'sourceId' => 'upload']]);

		$response = $this->controller()->install('t', 'abc', true, true, 'repin');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame(['message' => 'Pinned.', 'sourceId' => 'upload'], $response->getData());
	}

	public function testInstallingNeedsAStrictPasswordConfirmation(): void {
		$attributes = (new ReflectionMethod(UploadController::class, 'install'))->getAttributes(PasswordConfirmationRequired::class);

		self::assertCount(1, $attributes);
		self::assertSame(['strict' => true], $attributes[0]->getArguments());
	}

	public function testInspectingNeedsAStrictPasswordConfirmation(): void {
		$attributes = (new ReflectionMethod(UploadController::class, 'inspect'))->getAttributes(PasswordConfirmationRequired::class);

		self::assertCount(1, $attributes);
		self::assertSame(['strict' => true], $attributes[0]->getArguments());
	}

	private function controller(bool $uploaded = true): UploadController {
		return new class('versioniq', $this->request, $this->uploads, $this->groups, $this->session, $this->signatures, $this->installer, $uploaded) extends UploadController {
			public function __construct(
				string $appName,
				IRequest $request,
				UploadedArchive $uploads,
				IGroupManager $groups,
				IUserSession $session,
				StoreSignatureCheck $signatures,
				InstallerService $installer,
				private bool $uploaded,
			) {
				parent::__construct($appName, $request, $uploads, $groups, $session, $signatures, $installer);
			}

			protected function isUpload(string $path): bool {
				return $this->uploaded;
			}
		};
	}
}
