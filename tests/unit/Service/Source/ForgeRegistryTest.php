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

	// Issue #437: Codeberg is retired as a forge of its own, and another
	// Forgejo or Gitea host gets a generic `forgejo` forge with a host the
	// admin configures, instead of riding on forge.codeberg.api_base.

	public function testForgejoIsAbsentUntilAHostIsConfigured(): void {
		$registry = $this->registry();

		self::assertFalse($registry->has(ForgeRegistry::FORGE_FORGEJO));
		self::assertSame([ForgeRegistry::FORGE_GITHUB], $registry->selectableIds());
	}

	public function testForgejoHostDerivesApiAndTokenUrls(): void {
		$registry = $this->registry(['forge.forgejo.web_base' => 'https://git.example.org/']);
		$forge = $registry->get(ForgeRegistry::FORGE_FORGEJO);

		self::assertSame('https://git.example.org', $forge->webBaseUrl);
		self::assertSame('https://git.example.org/api/v1', $forge->apiBaseUrl);
		self::assertSame('https://git.example.org/user/settings/applications', $forge->tokenCreateUrl);
		self::assertSame(Forge::SCHEME_TOKEN, $forge->authScheme);
		self::assertFalse($forge->exposesScopeHeader);
		self::assertSame([ForgeRegistry::FORGE_GITHUB, ForgeRegistry::FORGE_FORGEJO], $registry->selectableIds());
		self::assertSame('https://git.example.org', $registry->forgejoHost());
	}

	public function testForgejoApiBaseOverrideWins(): void {
		$forge = $this->registry([
			'forge.forgejo.web_base' => 'http://forge-fixture:9099',
			'forge.forgejo.api_base' => 'http://forge-fixture:9099/custom/api',
		])->get(ForgeRegistry::FORGE_FORGEJO);

		self::assertSame('http://forge-fixture:9099/custom/api', $forge->apiBaseUrl);
	}

	public function testCodebergIsRetiredButStoredBindingsStillResolve(): void {
		$registry = $this->registry(['forge.forgejo.web_base' => 'https://git.example.org']);

		self::assertTrue($registry->has(ForgeRegistry::FORGE_CODEBERG));
		self::assertTrue($registry->isRetired(ForgeRegistry::FORGE_CODEBERG));
		self::assertFalse($registry->isRetired(ForgeRegistry::FORGE_FORGEJO));
		self::assertNotContains(ForgeRegistry::FORGE_CODEBERG, $registry->selectableIds());
		// A stored codeberg binding keeps reading codeberg.org through the
		// same Forgejo API shape the generic forge uses.
		self::assertSame('https://codeberg.org/api/v1', $registry->get(ForgeRegistry::FORGE_CODEBERG)->apiBaseUrl);
	}
}
