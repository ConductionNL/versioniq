<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins every public field of every forge the registry builds, with and
 * without app-config overrides.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ForgeFieldsGoldenTest extends TestCase {
	/**
	 * @return array<string, array{0: array<string, string>, 1: array<string, array<string, mixed>>}>
	 */
	public static function configurations(): array {
		$github = ['id' => 'github', 'apiBaseUrl' => 'https://api.github.com', 'webBaseUrl' => 'https://github.com', 'authScheme' => 'Bearer', 'exposesScopeHeader' => true, 'tokenCreateUrl' => 'https://github.com/settings/tokens'];
		$codeberg = ['id' => 'codeberg', 'apiBaseUrl' => 'https://codeberg.org/api/v1', 'webBaseUrl' => 'https://codeberg.org', 'authScheme' => 'token', 'exposesScopeHeader' => false, 'tokenCreateUrl' => 'https://codeberg.org/user/settings/applications'];

		return [
			'defaults' => [[], ['github' => $github, 'codeberg' => $codeberg]],
			'overrides with trailing slashes' => [
				['forge.github.api_base' => 'https://ghe.example.test/api/v3/', 'forge.github.web_base' => ' https://ghe.example.test/ '],
				['github' => array_replace($github, ['apiBaseUrl' => 'https://ghe.example.test/api/v3', 'webBaseUrl' => 'https://ghe.example.test']), 'codeberg' => $codeberg],
			],
			'forgejo host only' => [
				['forge.forgejo.web_base' => 'https://git.example.test/'],
				['github' => $github, 'codeberg' => $codeberg, 'forgejo' => ['id' => 'forgejo', 'apiBaseUrl' => 'https://git.example.test/api/v1', 'webBaseUrl' => 'https://git.example.test', 'authScheme' => 'token', 'exposesScopeHeader' => false, 'tokenCreateUrl' => 'https://git.example.test/user/settings/applications']],
			],
			'forgejo with its own api' => [
				['forge.forgejo.web_base' => 'https://git.example.test', 'forge.forgejo.api_base' => 'https://api.git.example.test'],
				['github' => $github, 'codeberg' => $codeberg, 'forgejo' => ['id' => 'forgejo', 'apiBaseUrl' => 'https://api.git.example.test', 'webBaseUrl' => 'https://git.example.test', 'authScheme' => 'token', 'exposesScopeHeader' => false, 'tokenCreateUrl' => 'https://git.example.test/user/settings/applications']],
			],
		];
	}

	/**
	 * @dataProvider configurations
	 * @param array<string, string> $overrides
	 * @param array<string, array<string, mixed>> $expected
	 */
	public function testEveryForgeField(array $overrides, array $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string => $overrides[$key] ?? $default,
		);
		$registry = new ForgeRegistry($appConfig);

		$actual = [];
		foreach (array_keys($expected) as $id) {
			$this->assertTrue($registry->has($id), $id);
			$actual[$id] = get_object_vars($registry->get($id));
		}
		$this->assertSame($expected, $actual);
		$this->assertSame(isset($expected['forgejo']), $registry->has('forgejo'));
	}
}
