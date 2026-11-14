<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\Installer\ForceEnabler;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The HTTP side of "Install anyway" and "Enable anyway"
 * (install-force-incompatible D2, D3).
 */
final class ApiForceIncompatibleTest extends TestCase {
	use BuildsByParameterName;
	use DispatchesLikeNextcloud;

	/** @var list<InstallRequest> */
	private array $installs = [];

	private function controller(IRequest $request, ?string $uid, ?ForceEnabler $enabler = null): ApiController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$installer = $this->createMock(InstallerService::class);
		$installer->method('installAppVersion')->willReturnCallback(function (InstallRequest $request): array {
			$this->installs[] = $request;

			return ['statusCode' => 200, 'payload' => ['installStatus' => 'installed']];
		});

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groupManager,
			'installerService' => $installer,
			'forceEnabler' => $enabler ?? $this->createMock(ForceEnabler::class),
			'serverVersion' => (new \ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
		]);
	}

	/**
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function forceFlags(): array {
		return [
			'1' => ['1', true],
			'true' => ['true', true],
			'0' => ['0', false],
			'left out' => [null, false],
		];
	}

	/**
	 * @dataProvider forceFlags
	 */
	public function testTheInstallRouteReadsForceIncompatible(mixed $flag, bool $forced): void {
		$params = ['appId' => 'hermiq', 'version' => '1.4.0'] + ($flag === null ? [] : ['forceIncompatible' => $flag]);
		$request = $this->requestWith($params);

		$response = $this->dispatch($this->controller($request, 'admin'), 'installVersion', $request);

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->installs);
		$this->assertSame($forced, $this->installs[0]->options->forceIncompatible);
	}

	public function testForceEnableRefusesANonAdminWithoutCallingTheEnabler(): void {
		$enabler = $this->createMock(ForceEnabler::class);
		$enabler->expects($this->never())->method('enable');
		$request = $this->requestWith(['appId' => 'hermiq']);

		$response = $this->dispatch($this->controller($request, 'bob', $enabler), 'forceEnable', $request);

		$this->assertSame(['class' => 'DataResponse', 'status' => 403, 'data' => ['message' => 'Forbidden']], self::describe($response));
	}

	public function testForceEnablePassesTheAdminAndTheOutcomeThrough(): void {
		$enabler = $this->createMock(ForceEnabler::class);
		$enabler->expects($this->once())->method('enable')->with('hermiq', 'admin')
			->willReturn(['statusCode' => 409, 'payload' => ['appId' => 'hermiq', 'message' => 'Nextcloud did not enable the app: no']]);
		$request = $this->requestWith(['appId' => ' hermiq ']);

		$response = $this->dispatch($this->controller($request, 'admin', $enabler), 'forceEnable', $request);

		$this->assertSame(['class' => 'DataResponse', 'status' => 409, 'data' => ['appId' => 'hermiq', 'message' => 'Nextcloud did not enable the app: no']], self::describe($response));
	}

	public function testForceEnableNeedsThePassword(): void {
		$attributes = (new \ReflectionMethod(ApiController::class, 'forceEnable'))->getAttributes(PasswordConfirmationRequired::class);

		$this->assertCount(1, $attributes);
	}
}
