<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\Installer\InstallProgress;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use OCP\ServerVersion;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * install-live-progress task 2.1: the install route closes the session and
 * records progress under the page's token; GET /api/install-progress/{token}
 * serves that record to admins and 404s an unknown token.
 *
 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md
 */
final class ApiInstallProgressTest extends TestCase {
	use BuildsByParameterName;

	private const TOKEN = 'k3Jw9-q2Lx_7Hn4Pz8Rt';

	/** @var list<string> */
	private array $calls = [];

	/** @var InstallProgress&MockObject */
	private InstallProgress $progress;

	/** @var ISession&MockObject */
	private ISession $session;

	/** @var InstallerService&MockObject */
	private InstallerService $installer;

	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(array $params = []): ApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$this->progress = $this->createMock(InstallProgress::class);
		$this->session = $this->createMock(ISession::class);
		$this->installer = $this->createMock(InstallerService::class);

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $userSession,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			'installerService' => $this->installer,
			'connectionReports' => null,
			'progress' => $this->progress,
			'session' => $this->session,
		]);
	}

	public function testAnUnknownTokenIsNotFound(): void {
		$controller = $this->controller();
		$this->progress->method('read')->with('nope-nope-nope-nope')->willReturn(null);

		$response = $controller->installProgress('nope-nope-nope-nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAKnownTokenReturnsItsRecord(): void {
		$controller = $this->controller();
		$record = ['stages' => [['name' => 'downloaded', 'startedAt' => 1, 'endedAt' => 2, 'detail' => []]], 'outcome' => null];
		$this->progress->method('read')->with(self::TOKEN)->willReturn($record);

		$response = $controller->installProgress(self::TOKEN);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($record, $response->getData());
	}

	public function testAnInstallClosesTheSessionThenRecordsProgressUnderTheToken(): void {
		$controller = $this->controller(['progressToken' => self::TOKEN]);
		$this->session->expects($this->once())->method('close')->willReturnCallback(function (): void {
			$this->calls[] = 'session.close';
		});
		$this->session->expects($this->never())->method('set');
		$this->progress->expects($this->once())->method('start')->with(self::TOKEN)->willReturnCallback(function (): void {
			$this->calls[] = 'progress.start';
		});
		$this->installer->method('installAppVersion')->willReturnCallback(function (): array {
			$this->calls[] = 'install';
			return ['statusCode' => 200, 'payload' => ['status' => 'installed']];
		});
		$this->progress->expects($this->once())->method('finish')->with('installed')->willReturnCallback(function (): void {
			$this->calls[] = 'progress.finish';
		});

		$controller->installVersion('mail', '4.0.0');

		$this->assertSame(['session.close', 'progress.start', 'install', 'progress.finish'], $this->calls);
	}

	public function testAFailedInstallFinishesAsFailedAndNoTokenStartsNothing(): void {
		$controller = $this->controller();
		$this->session->expects($this->once())->method('close');
		$this->progress->expects($this->never())->method('start');
		$this->installer->method('installAppVersion')->willReturn(['statusCode' => 422, 'payload' => ['status' => 'error']]);
		$this->progress->expects($this->once())->method('finish')->with('failed');

		$controller->installVersion('mail', '4.0.0');
	}

	public function testTheInstallPathNeverUsesTheSession(): void {
		$files = array_merge(
			[__DIR__ . '/../../../lib/Service/InstallerService.php', __DIR__ . '/../../../lib/Service/SelectedReleaseInstallerService.php', __DIR__ . '/../../../lib/Service/ExternalReleaseInstallerService.php'],
			glob(__DIR__ . '/../../../lib/Service/Installer/*.php') ?: [],
		);

		foreach ($files as $file) {
			$this->assertStringNotContainsString('ISession', (string)file_get_contents($file), basename($file) . ' must not touch the session the install route closed');
		}
	}
}
