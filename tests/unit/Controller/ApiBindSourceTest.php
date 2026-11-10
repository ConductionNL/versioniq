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
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every outcome of binding a source to an app: the response, and what the
 * installer service was asked to bind and read back.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 */
final class ApiBindSourceTest extends TestCase {
	use BuildsByParameterName;
	use DispatchesLikeNextcloud;

	/**
	 * @dataProvider bindings
	 * @param array<string, string> $params
	 * @param array<string, mixed> $expected
	 */
	public function testEveryOutcome(array $params, string $installer, array $expected): void {
		$calls = [];
		$service = $this->createMock(InstallerService::class);
		$service->method('bindSource')->willReturnCallback(static function (string $appId, SourceBinding $binding) use ($installer, &$calls): void {
			$calls[] = ['bindSource', $appId, $binding->getId()];
			if ($installer === 'untrusted') {
				throw new UntrustedSourceException($binding->getId(), 'not on the list');
			}
		});
		$service->method('getBinding')->willReturnCallback(static function (string $appId) use ($installer, &$calls): ?SourceBinding {
			$calls[] = ['getBinding', $appId];

			return $installer === 'persisted' ? SourceBinding::release('github', 'acme', 'widget', '*.zip') : null;
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$controller = $this->buildByName(ApiController::class, [
			'appName' => 'versioniq',
			'request' => $this->requestWith($params),
			'installerService' => $service,
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
			'appManager' => $this->createMock(IAppManager::class),
			'connectionReports' => null,
		]);

		$response = $controller->bindSource('widget');

		$this->assertSame($expected, ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $calls]);
	}

	/**
	 * @return iterable<string, array{0: array<string, string>, 1: string, 2: array<string, mixed>}>
	 */
	public static function bindings(): iterable {
		$release = ['kind' => 'github-release', 'owner' => 'acme', 'repo' => 'widget'];
		$appStore = SourceBinding::appStore()->toArray();
		$github = SourceBinding::release('github', 'acme', 'widget')->toArray();
		yield 'app store' => [['kind' => 'appstore'], 'ok', ['status' => 200, 'data' => ['appId' => 'widget', 'sourceId' => 'appstore', 'binding' => $appStore], 'calls' => [['bindSource', 'widget', 'appstore'], ['getBinding', 'widget']]]];
		yield 'github release, default forge and pattern' => [$release, 'ok', ['status' => 200, 'data' => ['appId' => 'widget', 'sourceId' => 'github:acme/widget', 'binding' => $github], 'calls' => [['bindSource', 'widget', 'github:acme/widget'], ['getBinding', 'widget']]]];
		yield 'persisted binding wins' => [$release, 'persisted', ['status' => 200, 'data' => ['appId' => 'widget', 'sourceId' => 'github:acme/widget', 'binding' => SourceBinding::release('github', 'acme', 'widget', '*.zip')->toArray()], 'calls' => [['bindSource', 'widget', 'github:acme/widget'], ['getBinding', 'widget']]]];
		yield 'unknown kind' => [['kind' => 'svn'], 'ok', ['status' => 400, 'data' => ['message' => 'Unknown source kind: svn'], 'calls' => []]];
		yield 'no kind' => [[], 'ok', ['status' => 400, 'data' => ['message' => 'Unknown source kind: '], 'calls' => []]];
		yield 'untrusted' => [$release, 'untrusted', ['status' => 403, 'data' => ['message' => (new UntrustedSourceException('github:acme/widget', 'not on the list'))->getMessage()], 'calls' => [['bindSource', 'widget', 'github:acme/widget']]]];
	}
}
