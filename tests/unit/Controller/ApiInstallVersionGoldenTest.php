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
require_once __DIR__ . '/../Support/InstallArgs.php';
require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden over POST /api/app/{appId}/versions/{version}/install: for each set
 * of request values, dispatched the way Nextcloud does, the install the
 * controller asks for (the nine install values, with a dry run left out shown
 * as the debug flag it falls back to) and the response, compared with
 * fixtures/api-install-version.golden.json. Set RECORD_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 */
final class ApiInstallVersionGoldenTest extends TestCase {
	use BuildsByParameterName;
	use DispatchesLikeNextcloud;

	private const FIXTURE = __DIR__ . '/fixtures/api-install-version.golden.json';

	private const ROUTE = ['appId' => 'notes', 'version' => '2.0.0'];

	/** @var list<array<string, mixed>> */
	private array $installs = [];

	/**
	 * @return array<string, array{params: array<string, mixed>, uid?: string|null}>
	 */
	private static function cases(): array {
		return [
			'route values only' => ['params' => []],
			'target version param wins' => ['params' => ['targetVersion' => '2.1.0']],
			'blank target version keeps the route version' => ['params' => ['targetVersion' => '  ']],
			'source trimmed' => ['params' => ['source' => ' github:acme/notes ']],
			'blank source' => ['params' => ['source' => '  ']],
			'debug implies dry run' => ['params' => ['debug' => '1']],
			'debug with explicit real run' => ['params' => ['debug' => '1', 'dryRun' => '0']],
			'dry run' => ['params' => ['dryRun' => 'true']],
			'dry run spelled false, padded' => ['params' => ['debug' => '1', 'dryRun' => ' FALSE ']],
			'dry run spelled with capitals' => ['params' => ['dryRun' => 'True']],
			'dry run that is no flag' => ['params' => ['debug' => '1', 'dryRun' => 'maybe']],
			'pin with override' => ['params' => ['pin' => '1', 'overridePin' => '1.9.0']],
			'accept new sha' => ['params' => ['acceptNewSha' => '1']],
			'allow downgrade' => ['params' => ['allowDowngrade' => 'true']],
			'flags spelled false' => ['params' => ['debug' => 'false', 'pin' => 'FALSE', 'acceptNewSha' => '0', 'allowDowngrade' => 'no']],
			'every default spelled out' => ['params' => ['source' => null, 'debug' => '0', 'dryRun' => null, 'pin' => '0', 'acceptNewSha' => '0', 'allowDowngrade' => '0']],
			'unknown parameter ignored' => ['params' => ['force' => '1']],
			'everything' => ['params' => ['source' => 'codeberg:acme/notes', 'debug' => 'true', 'dryRun' => '1', 'pin' => 'true', 'acceptNewSha' => 'true', 'allowDowngrade' => '1', 'overridePin' => '1.0.0', 'targetVersion' => '2.2.0']],
			'not an admin' => ['params' => ['debug' => '1'], 'uid' => 'alice'],
			'no user' => ['params' => ['debug' => '1'], 'uid' => null],
			'debug as a boolean' => ['params' => ['debug' => true]],
			'dry run as a number' => ['params' => ['dryRun' => 1]],
			'source as a list' => ['params' => ['source' => ['github:acme/notes']]],
			'pin as a boolean' => ['params' => ['pin' => false]],
			'accept new sha as a number' => ['params' => ['acceptNewSha' => 1.0]],
			'allow downgrade as a boolean' => ['params' => ['allowDowngrade' => true]],
			'wrong type from a non-admin' => ['params' => ['debug' => true], 'uid' => 'alice'],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$case = self::cases()[$name];
		$this->installs = [];
		$request = $this->requestWith(self::ROUTE + $case['params']);
		$response = $this->dispatch($this->controller($request, array_key_exists('uid', $case) ? $case['uid'] : 'admin'), 'installVersion', $request);
		$actual = json_encode(self::describe($response) + ['installs' => $this->installs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	private function controller(IRequest $request, ?string $uid): ApiController {
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
		$installer->method('installAppVersion')->willReturnCallback(function (mixed ...$args): array {
			$install = InstallArgs::named($args);
			$install['dryRun'] ??= $install['includeDebug'];
			ksort($install);
			$this->installs[] = $install;

			return ['statusCode' => 200, 'payload' => ['installStatus' => 'installed']];
		});

		return $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groupManager,
			'installerService' => $installer,
			'serverVersion' => (new \ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
		]);
	}
}
