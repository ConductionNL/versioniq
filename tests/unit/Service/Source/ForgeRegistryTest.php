<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\Versioniq\Service\Source\Forge;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/external-sources/spec.md
 */
final class ForgeRegistryTest extends TestCase {
	/**
	 * @param array<string, string> $overrides keyed by the full app-config key
	 *                                         (e.g. `forge.github.api_base`)
	 */
	private function registry(array $overrides = []): ForgeRegistry {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string => $overrides[$key] ?? $default,
		);

		return new ForgeRegistry($appConfig);
	}

	public function testGithubConfig(): void {
		$forge = $this->registry()->get(ForgeRegistry::FORGE_GITHUB);

		self::assertSame('https://api.github.com', $forge->apiBaseUrl);
		self::assertSame(Forge::SCHEME_BEARER, $forge->authScheme);
		self::assertTrue($forge->exposesScopeHeader);
		self::assertSame('Bearer abc', $forge->authHeaderValue('abc'));
		self::assertSame('https://api.github.com/repos/o/r/releases?per_page=100', $forge->releasesEndpoint('o/r'));
	}

	public function testCodebergConfig(): void {
		$forge = $this->registry()->get(ForgeRegistry::FORGE_CODEBERG);

		self::assertSame('https://codeberg.org/api/v1', $forge->apiBaseUrl);
		self::assertSame(Forge::SCHEME_TOKEN, $forge->authScheme);
		self::assertFalse($forge->exposesScopeHeader);
		self::assertSame('token abc', $forge->authHeaderValue('abc'));
		self::assertSame('https://codeberg.org/api/v1/user', $forge->userEndpoint());
	}

	public function testHas(): void {
		$registry = $this->registry();
		self::assertTrue($registry->has(ForgeRegistry::FORGE_CODEBERG));
		self::assertFalse($registry->has('gitlab'));
	}

	public function testUnknownForgeThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->registry()->get('gitlab');
	}

	public function testApiBaseOverrideIsApplied(): void {
		$forge = $this->registry(['forge.github.api_base' => 'http://forge-fixture:9099/api'])
			->get(ForgeRegistry::FORGE_GITHUB);

		self::assertSame('http://forge-fixture:9099/api', $forge->apiBaseUrl);
		self::assertSame('http://forge-fixture:9099/api/repos/o/r/releases?per_page=100', $forge->releasesEndpoint('o/r'));
	}

	public function testWebBaseOverrideIsApplied(): void {
		$forge = $this->registry(['forge.codeberg.web_base' => 'https://git.example.test'])
			->get(ForgeRegistry::FORGE_CODEBERG);

		self::assertSame('https://git.example.test', $forge->webBaseUrl);
		// The API base keeps its default when only the web base is overridden.
		self::assertSame('https://codeberg.org/api/v1', $forge->apiBaseUrl);
	}

	public function testTrailingSlashIsTrimmed(): void {
		$forge = $this->registry(['forge.github.api_base' => 'http://fixture:9099/api/'])
			->get(ForgeRegistry::FORGE_GITHUB);

		self::assertSame('http://fixture:9099/api', $forge->apiBaseUrl);
	}

	public function testBlankOverrideKeepsDefault(): void {
		$forge = $this->registry(['forge.github.api_base' => '   '])
			->get(ForgeRegistry::FORGE_GITHUB);

		self::assertSame('https://api.github.com', $forge->apiBaseUrl);
	}
}
