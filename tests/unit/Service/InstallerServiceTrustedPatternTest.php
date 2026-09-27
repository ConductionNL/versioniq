<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class InstallerServiceTrustedPatternTest extends TestCase {
	/** @var list<string> */
	private array $stored = [];

	private function service(array $initial = []): InstallerService {
		$this->stored = $initial;

		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->method('getPatterns')->willReturnCallback(fn (): array => $this->stored);
		$trusted->method('setPatterns')->willReturnCallback(function (array $patterns): void {
			$this->stored = array_values($patterns);
		});

		return new InstallerService(
			$this->createMock(IAppManager::class),
			$this->createMock(IConfig::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(SourceRegistry::class),
			$this->createMock(SourceBindingStore::class),
			$trusted,
			$this->createMock(SelectedReleaseInstallerService::class),
			$this->createMock(ExternalReleaseInstallerService::class),
			$this->createMock(FailureClassifier::class),
			$this->createMock(EnvironmentCheck::class),
			$this->createMock(PinStore::class),
			$this->createMock(IUserSession::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(\OCA\Versioniq\Service\Lkg\LkgStore::class),
			$this->createMock(\OCA\Versioniq\Service\Cache\ArtifactCache::class),
		);
	}

	public function testCuratedAddWithRepoPersistsForgeQualifiedPattern(): void {
		$result = $this->service()->addTrustedPattern('codeberg', 'Conduction', 'openregister');

		self::assertContains('codeberg:Conduction/openregister', $result);
		self::assertContains('codeberg:Conduction/openregister', $this->stored);
	}

	public function testOwnerOnlyAddPersistsWildcardRepo(): void {
		$result = $this->service()->addTrustedPattern('github', 'acme', null);

		self::assertContains('github:acme/*', $result);
	}

	public function testAddIsIdempotent(): void {
		$svc = $this->service(['github:acme/*']);
		$result = $svc->addTrustedPattern('github', 'acme', null);

		self::assertSame(['github:acme/*'], $result);
	}

	/**
	 * @dataProvider dangerousProvider
	 */
	public function testDangerousInputsRejected(string $forge, string $owner, ?string $repo): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service()->addTrustedPattern($forge, $owner, $repo);
	}

	/**
	 * @return array<string, array{string, string, ?string}>
	 */
	public static function dangerousProvider(): array {
		return [
			'wildcard owner' => ['github', '*', null],
			'empty owner' => ['github', '', null],
			'unknown forge' => ['gitlab', 'acme', null],
			'owner bad charset (slash)' => ['github', 'a/b', null],
			'owner bad charset (star)' => ['github', 'ac*me', null],
			'repo bad charset' => ['github', 'acme', 'wid*get'],
		];
	}

	public function testRemoveDeletesExactPattern(): void {
		$svc = $this->service(['github:acme/*', 'codeberg:Conduction/openregister']);
		$result = $svc->removeTrustedPattern('github:acme/*');

		self::assertNotContains('github:acme/*', $result);
		self::assertContains('codeberg:Conduction/openregister', $result);
	}

	public function testForgejoPatternIsAccepted(): void {
		$result = $this->service()->addTrustedPattern('forgejo', 'acme', null);

		self::assertContains('forgejo:acme/*', $result);
	}
}
