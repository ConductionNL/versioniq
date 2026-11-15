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
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for who may call the admin actions that act as a signed-in
 * user: pinApp, unpinApp, setPolicy, listPats, createPat, patchPat and
 * deletePat. Each action is called by nobody, by a user who is no admin, by an
 * admin whose session is gone on the second read, and by an admin. The
 * response (or what the unconfigured collaborators threw past the guard), and
 * every session and group-manager call in order, are compared with
 * fixtures/api-admin-caller.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @uses \OCA\Versioniq\Service\Api\AccessCollaborators
 * @uses \OCA\Versioniq\Service\Api\ApiCaller
 * @uses \OCA\Versioniq\Service\Api\InstallCollaborators
 * @uses \OCA\Versioniq\Service\Api\PatServices
 * @uses \OCA\Versioniq\Service\Api\ReportCollaborators
 * @uses \OCA\Versioniq\Service\Api\ReportStores
 * @uses \OCA\Versioniq\Service\AutoUpdate\AutoUpdateStores
 * @uses \OCA\Versioniq\Service\Discovery\DiscoveryFilter
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 * @uses \OCA\Versioniq\Service\Policy\Policy
 */
final class ApiAdminCallerGoldenTest extends TestCase {
	use BuildsByParameterName;
	use DispatchesLikeNextcloud;

	private const FIXTURE = __DIR__ . '/fixtures/api-admin-caller.golden.json';

	/** @var list<list<mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	private static function actions(): array {
		return [
			'pinApp' => ['pinApp', ['appId' => 'someapp']],
			'unpinApp' => ['unpinApp', ['appId' => 'someapp']],
			'setPolicy' => ['setPolicy', ['appId' => 'someapp', 'level' => 'patch']],
			'listPats' => ['listPats', []],
			'createPat' => ['createPat', []],
			'patchPat' => ['patchPat', ['id' => 7, 'label' => 'x']],
			'deletePat' => ['deletePat', ['id' => 7]],
		];
	}

	/**
	 * The callers: the users the session answers on each read, and whether the uid is an admin.
	 *
	 * @return array<string, array{0: list<string|null>, 1: bool}>
	 */
	private static function callers(): array {
		return [
			'signed out' => [[null], false],
			'user' => [['alice'], false],
			'admin, session gone on the second read' => [['root', null], true],
			'admin' => [['root'], true],
		];
	}

	public function testEveryCallerMatchesTheGolden(): void {
		$actual = [];
		foreach (self::actions() as $name => [$method, $params]) {
			foreach (self::callers() as $caller => [$users, $admin]) {
				$actual[$name . ' / ' . $caller] = $this->outcome($method, $params, $users, $admin);
			}
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'case list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(json_encode($expected[$name]), json_encode($case), "case {$name}");
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @param list<string|null> $users
	 * @return array<string, mixed>
	 */
	private function outcome(string $method, array $params, array $users, bool $admin): array {
		$this->calls = [];
		$controller = $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->requestWith($params),
			'userSession' => $this->session($users),
			'groupManager' => $this->groupManager($admin),
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'connectionReports' => null,
		]);

		try {
			$result = self::describe($this->dispatch($controller, $method, $this->requestWith($params)));
		} catch (\Throwable $error) {
			$result = ['threw' => get_class($error)];
		}

		return ['result' => $result, 'calls' => $this->calls];
	}

	/**
	 * @param list<string|null> $users
	 */
	private function session(array $users): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function () use (&$users): ?IUser {
			$uid = count($users) > 1 ? array_shift($users) : $users[0];
			$this->calls[] = ['getUser', $uid];
			if ($uid === null) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		});

		return $session;
	}

	private function groupManager(bool $admin): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(function (string $uid) use ($admin): bool {
			$this->calls[] = ['isAdmin', $uid];

			return $admin;
		});

		return $groups;
	}
}
