<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ForgeController;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Issue #437: the self-hosted Forgejo or Gitea host is set from the admin
 * page, not only by overriding forge.codeberg.api_base with occ.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ForgeControllerTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];

	private function controller(bool $admin = true): ForgeController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;

				return true;
			},
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			},
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new ForgeController(
			'versioniq',
			$this->createMock(IRequest::class),
			new ForgeRegistry($appConfig),
			$groups,
			$session,
		);
	}

	public function testNonAdminCannotReadOrWrite(): void {
		$controller = $this->controller(false);

		self::assertSame(403, $controller->forgejoHost()->getStatus());
		self::assertSame(403, $controller->setForgejoHost('https://git.example.org')->getStatus());
		self::assertSame([], $this->config);
	}

	public function testUnconfiguredHostReadsEmpty(): void {
		$response = $this->controller()->forgejoHost();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['host' => '', 'configured' => false], $response->getData());
	}

	public function testHttpsHostIsStoredWithoutTrailingSlash(): void {
		$response = $this->controller()->setForgejoHost(' https://git.example.org/ ');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['host' => 'https://git.example.org', 'configured' => true], $response->getData());
		self::assertSame('https://git.example.org', $this->config['forge.forgejo.web_base']);
	}

	public function testSavingAHostDropsAStaleApiBaseOverride(): void {
		$this->config['forge.forgejo.api_base'] = 'http://old-host/api/v1';

		$this->controller()->setForgejoHost('https://git.example.org');

		self::assertArrayNotHasKey('forge.forgejo.api_base', $this->config);
	}

	/**
	 * @dataProvider invalidHosts
	 */
	public function testInvalidHostIsRejected(string $host): void {
		$response = $this->controller()->setForgejoHost($host);

		self::assertSame(400, $response->getStatus());
		self::assertArrayNotHasKey('forge.forgejo.web_base', $this->config);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidHosts(): array {
		return [
			'plain http' => ['http://git.example.org'],
			'no scheme' => ['git.example.org'],
			'credentials' => ['https://user:pass@git.example.org'],
			'query' => ['https://git.example.org/?x=1'],
			'javascript' => ['javascript:alert(1)'],
		];
	}

	public function testEmptyHostClearsTheForge(): void {
		$this->config['forge.forgejo.web_base'] = 'https://git.example.org';

		$response = $this->controller()->setForgejoHost('');

		self::assertSame(['host' => '', 'configured' => false], $response->getData());
		self::assertArrayNotHasKey('forge.forgejo.web_base', $this->config);
	}
}
