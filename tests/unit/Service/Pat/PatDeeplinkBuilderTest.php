<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use InvalidArgumentException;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PatDeeplinkBuilderTest extends TestCase {
	private function buildBuilder(string $host = 'cloud.example.com'): PatDeeplinkBuilder {
		$request = $this->createMock(IRequest::class);
		$request->method('getServerHost')->willReturn($host);

		return new PatDeeplinkBuilder($request, new ForgeRegistry($this->createMock(\OCP\IAppConfig::class)));
	}

	public function testClassicDeeplinkContainsScopeAndDescription(): void {
		$result = $this->buildBuilder('cloud.example.com')->build(Pat::KIND_CLASSIC);

		$this->assertSame(Pat::KIND_CLASSIC, $result['kind']);
		$this->assertStringContainsString('https://github.com/settings/tokens/new?', $result['url']);
		$this->assertStringContainsString('scopes=repo', $result['url']);
		$this->assertStringContainsString('description=Nextcloud', $result['url']);
		$this->assertStringContainsString('cloud.example.com', $result['url']);
		$this->assertNotEmpty($result['instructions']);
	}

	public function testFineGrainedDeeplinkUsesPageUrlWithoutPrefill(): void {
		$result = $this->buildBuilder()->build(Pat::KIND_FINE_GRAINED);

		$this->assertSame(Pat::KIND_FINE_GRAINED, $result['kind']);
		$this->assertSame('https://github.com/settings/personal-access-tokens/new', $result['url']);
		$this->assertGreaterThan(2, count($result['instructions']));
		$this->assertTrue(
			str_contains(implode(' ', $result['instructions']), 'Read-only'),
			'Instructions should remind admin to use Read-only permissions'
		);
	}

	public function testCodebergDeeplinkReturnsForgeTokenKindAndUrl(): void {
		$result = $this->buildBuilder()->build(Pat::KIND_FORGE_TOKEN);

		$this->assertSame(Pat::KIND_FORGE_TOKEN, $result['kind']);
		$this->assertStringContainsString('codeberg.org', $result['url']);
		$this->assertNotEmpty($result['instructions']);
	}

	public function testUnknownKindRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->buildBuilder()->build('mystery');
	}

	public function testEmptyHostFallsBackToNextcloudLabel(): void {
		$result = $this->buildBuilder('')->build(Pat::KIND_CLASSIC);

		$this->assertStringContainsString('Nextcloud', $result['url']);
	}
}
