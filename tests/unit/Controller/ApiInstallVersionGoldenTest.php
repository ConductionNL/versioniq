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

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\InstallArgs;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden over POST /api/app/{appId}/versions/{version}/install: for each set
 * of request values, the install the controller asks for (the nine install
 * values, with a dry run left out shown as the debug flag it falls back to)
 * and the response, compared with fixtures/api-install-version.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 */
final class ApiInstallVersionGoldenTest extends TestCase {
	use BuildsByParameterName;

	private const FIXTURE = __DIR__ . '/fixtures/api-install-version.golden.json';

	/** @var list<array<string, mixed>> */
	private array $installs = [];

	/**
	 * @return array<string, array{args: list<mixed>, params?: array<string, mixed>}>
	 */
	private static function cases(): array {
		return [
			'route values only' => ['args' => ['notes', '2.0.0']],
			'target version param wins' => ['args' => ['notes', '2.0.0'], 'params' => ['targetVersion' => '2.1.0', 'version' => '2.2.0']],
			'version param' => ['args' => ['notes', '2.0.0'], 'params' => ['version' => '2.2.0']],
			'source trimmed' => ['args' => ['notes', '2.0.0', ' github:acme/notes ']],
			'blank source' => ['args' => ['notes', '2.0.0', '  ']],
			'debug implies dry run' => ['args' => ['notes', '2.0.0', null, '1']],
			'debug with explicit real run' => ['args' => ['notes', '2.0.0', null, '1', '0']],
			'dry run' => ['args' => ['notes', '2.0.0', null, '0', 'true']],
			'pin with override' => ['args' => ['notes', '2.0.0', null, '0', null, '1'], 'params' => ['overridePin' => '1.9.0']],
			'accept new sha' => ['args' => ['notes', '2.0.0', null, '0', null, '0', '1']],
			'allow downgrade' => ['args' => ['notes', '2.0.0', null, '0', null, '0', '0', 'true']],
			'everything' => ['args' => ['notes', '2.0.0', 'codeberg:acme/notes', 'true', '1', 'true', 'true', '1'], 'params' => ['overridePin' => '1.0.0']],
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
		$response = $this->controller($case['params'] ?? [])->installVersion(...$case['args']);
		$actual = json_encode(['status' => $response->getStatus(), 'data' => $response->getData(), 'installs' => $this->installs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function controller(array $params): ApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
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
