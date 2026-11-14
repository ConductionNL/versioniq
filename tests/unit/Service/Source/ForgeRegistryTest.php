<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

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
		self::assertTrue($registry->has(ForgeRegistry::FORGE_GITLAB));
		self::assertFalse($registry->has('bitbucket'));
	}

	public function testUnknownForgeThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->registry()->get('bitbucket');
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
		self::assertSame([ForgeRegistry::FORGE_GITHUB, ForgeRegistry::FORGE_GITLAB], $registry->selectableIds());
	}

	public function testForgejoHostDerivesApiAndTokenUrls(): void {
		$registry = $this->registry(['forge.forgejo.web_base' => 'https://git.example.org/']);
		$forge = $registry->get(ForgeRegistry::FORGE_FORGEJO);

		self::assertSame('https://git.example.org', $forge->webBaseUrl);
		self::assertSame('https://git.example.org/api/v1', $forge->apiBaseUrl);
		self::assertSame('https://git.example.org/user/settings/applications', $forge->tokenCreateUrl);
		self::assertSame(Forge::SCHEME_TOKEN, $forge->authScheme);
		self::assertFalse($forge->exposesScopeHeader);
		self::assertSame([ForgeRegistry::FORGE_GITHUB, ForgeRegistry::FORGE_FORGEJO, ForgeRegistry::FORGE_GITLAB], $registry->selectableIds());
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

	/**
	 * gitlab.com by default: the v4 API, Bearer tokens, the token page, the GitLab dialect.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function testGitlabDefaultsToGitlabCom(): void {
		$registry = $this->registry();
		$forge = $registry->get(ForgeRegistry::FORGE_GITLAB);

		self::assertSame('https://gitlab.com', $forge->webBaseUrl);
		self::assertSame('https://gitlab.com/api/v4', $forge->apiBaseUrl);
		self::assertSame('https://gitlab.com/-/user_settings/personal_access_tokens', $forge->tokenCreateUrl);
		self::assertSame('Bearer abc', $forge->authHeaderValue('abc'));
		self::assertSame(Forge::DIALECT_GITLAB, $forge->dialect);
		self::assertSame('https://gitlab.com', $registry->gitlabHost());
	}

	/**
	 * A self-managed host moves the web, API and token addresses with it.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function testASelfManagedGitlabHostMovesEveryAddress(): void {
		$forge = $this->registry(['forge.gitlab.web_base' => 'https://gitlab.example.org/'])->get(ForgeRegistry::FORGE_GITLAB);

		self::assertSame('https://gitlab.example.org', $forge->webBaseUrl);
		self::assertSame('https://gitlab.example.org/api/v4', $forge->apiBaseUrl);
		self::assertSame('https://gitlab.example.org/-/user_settings/personal_access_tokens', $forge->tokenCreateUrl);
	}

	/**
	 * setGitlabHost stores an https host, drops the stale API override, and
	 * an empty value goes back to gitlab.com.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function testSetGitlabHostStoresAnHttpsHostAndClearsTheApiOverride(): void {
		$stored = ['forge.gitlab.api_base' => 'https://old.example.org/api/v4'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return $stored[$key] ?? $default;
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use (&$stored): void {
				unset($stored[$key]);
			}
		);
		$registry = new ForgeRegistry($appConfig);

		$registry->setGitlabHost(' https://gitlab.example.org/ ');
		self::assertSame(['forge.gitlab.web_base' => 'https://gitlab.example.org'], $stored);
		self::assertSame('https://gitlab.example.org/api/v4', $registry->get(ForgeRegistry::FORGE_GITLAB)->apiBaseUrl);

		$registry->setGitlabHost('');
		self::assertSame([], $stored);
		self::assertSame('https://gitlab.com', $registry->gitlabHost());
	}

	/**
	 * An http host, or one with credentials or a query, is refused.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function testAnHttpGitlabHostIsRefused(): void {
		foreach (['http://gitlab.example.org', 'https://user:pass@gitlab.example.org', 'https://gitlab.example.org?x=1', 'gitlab.example.org'] as $host) {
			try {
				$this->registry()->setGitlabHost($host);
				self::fail($host . ' was accepted');
			} catch (InvalidArgumentException $error) {
				self::assertStringContainsString('https address', $error->getMessage(), $host);
			}
		}
	}
}
