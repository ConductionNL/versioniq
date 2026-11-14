<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use InvalidArgumentException;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class InstallerServiceTrustedPatternTest extends TestCase {
	use BuildsByParameterName;

	/** @var list<string> */
	private array $stored = [];

	private function service(array $initial = []): InstallerService {
		$this->stored = $initial;

		// Only the store is doubled: trust() and untrust() run for real on it.
		$trusted = $this->getMockBuilder(TrustedSourceList::class)
			->setConstructorArgs([$this->createMock(IAppConfig::class)])
			->onlyMethods(['getPatterns', 'setPatterns'])
			->getMock();
		$trusted->method('getPatterns')->willReturnCallback(fn (): array => $this->stored);
		$trusted->method('setPatterns')->willReturnCallback(function (array $patterns): void {
			$this->stored = array_values($patterns);
		});

		return $this->buildByName(InstallerService::class, [
			'appManager' => $this->createMock(IAppManager::class),
			'config' => $this->createMock(IConfig::class),
			'appConfig' => $this->createMock(IAppConfig::class),
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $this->createMock(SourceBindingStore::class),
			'trustedSources' => $trusted,
			'signedInstaller' => $this->createMock(SelectedReleaseInstallerService::class),
			'externalInstaller' => $this->createMock(ExternalReleaseInstallerService::class),
			'failureClassifier' => $this->createMock(FailureClassifier::class),
			'environmentCheck' => $this->createMock(EnvironmentCheck::class),
			'pinStore' => $this->createMock(PinStore::class),
			'userSession' => $this->createMock(IUserSession::class),
			'timeFactory' => $this->createMock(ITimeFactory::class),
			'lkgStore' => $this->createMock(\OCA\Versioniq\Service\Lkg\LkgStore::class),
			'artifactCache' => $this->createMock(\OCA\Versioniq\Service\Cache\ArtifactCache::class),
			'eventDispatcher' => $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
		]);
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

	/**
	 * Binding an app to a source outside the allowlist is refused before
	 * anything is stored.
	 */
	public function testBindingAnUntrustedSourceStoresNothing(): void {
		$trusted = $this->createMock(TrustedSourceList::class);
		$trusted->method('assertBindingAllowed')->willThrowException(new UntrustedSourceException('github:evil/app'));
		$bindingStore = $this->createMock(SourceBindingStore::class);
		$bindingStore->expects($this->never())->method('set');

		$service = $this->buildByName(InstallerService::class, [
			'trustedSources' => $trusted,
			'bindingStore' => $bindingStore,
		]);

		$this->expectException(UntrustedSourceException::class);
		$service->bindSource('evilapp', SourceBinding::github('evil', 'app'));
	}
	/**
	 * Every accepted pattern and every refusal message, including which check
	 * wins when several fail (forge, then owner, then repo, then breadth).
	 *
	 * @return iterable<string, array{0: string, 1: string, 2: ?string, 3: string}>
	 */
	public static function patternOutcomes(): iterable {
		yield 'owner and repo' => ['github', 'acme', 'tool', 'github:acme/tool'];
		yield 'owner only' => ['github', 'acme', null, 'github:acme/*'];
		yield 'empty repo means every repo' => ['forgejo', 'acme', '', 'forgejo:acme/*'];
		yield 'blank repo means every repo' => ['forgejo', 'acme', '   ', 'forgejo:acme/*'];
		yield 'owner and repo are trimmed' => ['codeberg', ' acme ', ' tool ', 'codeberg:acme/tool'];
		yield 'dots dashes underscores' => ['github', 'a.b-c_d', 'R.e-p_0', 'github:a.b-c_d/R.e-p_0'];
		yield 'unknown forge' => ['gitlab', 'acme', 'tool', 'REFUSED Unknown forge: gitlab'];
		yield 'unknown forge beats bad owner' => ['gitlab', '*', 'wid*get', 'REFUSED Unknown forge: gitlab'];
		yield 'forge is case sensitive' => ['GitHub', 'acme', null, 'REFUSED Unknown forge: GitHub'];
		yield 'empty owner' => ['github', '', null, 'REFUSED A concrete owner is required (wildcard-only owners are not allowed).'];
		yield 'blank owner' => ['github', '  ', null, 'REFUSED A concrete owner is required (wildcard-only owners are not allowed).'];
		yield 'wildcard owner' => ['github', ' * ', 'tool', 'REFUSED A concrete owner is required (wildcard-only owners are not allowed).'];
		yield 'owner with slash' => ['github', 'a/b', null, 'REFUSED Owner contains invalid characters.'];
		yield 'owner with star beats bad repo' => ['github', 'ac*me', 'wid*get', 'REFUSED Owner contains invalid characters.'];
		yield 'repo with star' => ['github', 'acme', 'wid*get', 'REFUSED Repository contains invalid characters.'];
		yield 'repo with traversal' => ['github', 'acme', '../x', 'REFUSED Repository contains invalid characters.'];
		yield 'repo wildcard only' => ['github', 'acme', '*', 'REFUSED Repository contains invalid characters.'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('patternOutcomes')]
	public function testPatternOutcomeIsPinned(string $forge, string $owner, ?string $repo, string $expected): void {
		try {
			$result = $this->service()->addTrustedPattern($forge, $owner, $repo);
		} catch (InvalidArgumentException $e) {
			self::assertSame($expected, 'REFUSED ' . $e->getMessage());
			self::assertSame([], $this->stored);

			return;
		}

		self::assertSame([$expected], $result);
		self::assertSame([$expected], $this->stored);
	}
}
