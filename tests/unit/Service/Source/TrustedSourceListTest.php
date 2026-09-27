<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class TrustedSourceListTest extends TestCase {
	private function withPatterns(string $stored): TrustedSourceList {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $app === Application::APP_ID && $key === 'trusted_sources'
				? $stored
				: $default
		);

		return new TrustedSourceList($config);
	}

	public function testDefaultAllowsConductionNl(): void {
		$list = $this->withPatterns('');

		$this->assertTrue($list->isAllowed('github:ConductionNL/openregister'));
		$this->assertTrue($list->isAllowed('github:ConductionNL/anything'));
	}

	public function testDefaultRejectsRandomOwner(): void {
		$list = $this->withPatterns('');

		$this->assertFalse($list->isAllowed('github:randomuser/randomapp'));
	}

	public function testAppStoreSourceAlwaysAllowed(): void {
		$list = $this->withPatterns('["only-this/repo"]');

		$this->assertTrue($list->isAllowed('appstore'));
	}

	public function testAssertAllowedThrowsOnReject(): void {
		$list = $this->withPatterns('');

		$this->expectException(UntrustedSourceException::class);
		$list->assertAllowed('github:randomuser/randomapp');
	}

	public function testCustomGlobsAreUsed(): void {
		$list = $this->withPatterns('["myorg/*", "single/repo"]');

		$this->assertTrue($list->isAllowed('github:myorg/foo'));
		$this->assertTrue($list->isAllowed('github:single/repo'));
		$this->assertFalse($list->isAllowed('github:other/repo'));
		$this->assertFalse($list->isAllowed('github:single/other'));
	}

	public function testEmptyArrayFallsBackToDefault(): void {
		$list = $this->withPatterns('[]');

		$this->assertTrue($list->isAllowed('github:ConductionNL/foo'));
	}

	public function testInvalidJsonFallsBackToDefault(): void {
		$list = $this->withPatterns('not json');

		$this->assertTrue($list->isAllowed('github:ConductionNL/foo'));
		$this->assertFalse($list->isAllowed('github:other/foo'));
	}

	public function testMalformedSourceIdRejected(): void {
		$list = $this->withPatterns('');

		$this->assertFalse($list->isAllowed('github:nope-no-slash'));
		$this->assertFalse($list->isAllowed('not-a-source'));
		$this->assertFalse($list->isAllowed('github:'));
		$this->assertFalse($list->isAllowed('github://repo'));
	}

	public function testCodebergDefaultAllowedAndCrossForgeIsolated(): void {
		$list = $this->withPatterns('');

		// Default trusts Conduction on Codeberg but ConductionNL on GitHub.
		$this->assertTrue($list->isAllowed('codeberg:Conduction/pipelinq'));
		$this->assertFalse($list->isAllowed('codeberg:ConductionNL/pipelinq'));
		// Cross-forge isolation: the github default owner is not trusted on codeberg and vice-versa.
		$this->assertFalse($list->isAllowed('github:Conduction/pipelinq'));
	}

	public function testLegacyBarePatternNormalizesToGithub(): void {
		$list = $this->withPatterns('["acme/*"]');

		$this->assertTrue($list->isAllowed('github:acme/widget'));
		// A bare legacy pattern only trusts GitHub, never Codeberg.
		$this->assertFalse($list->isAllowed('codeberg:acme/widget'));
	}

	public function testForgeQualifiedCustomPattern(): void {
		$list = $this->withPatterns('["codeberg:acme/*"]');

		$this->assertTrue($list->isAllowed('codeberg:acme/widget'));
		$this->assertFalse($list->isAllowed('github:acme/widget'));
	}

	public function testForgejoPatternAllowsForgejoSourceOnly(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('["forgejo:acme/*"]');
		$list = new TrustedSourceList($config);

		$this->assertTrue($list->isAllowed('forgejo:acme/widget'));
		$this->assertFalse($list->isAllowed('github:acme/widget'));
		$this->assertFalse($list->isAllowed('codeberg:acme/widget'));
	}
}
