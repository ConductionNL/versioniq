<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\PublisherTrust;
use OCA\Versioniq\Service\ExternalReleaseInstallerService;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Apps tab list: enabled apps, installed-but-disabled apps and apps bound
 * to a source but not installed yet, each with its state (issue #433).
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstallerServiceAppListTest extends TestCase {
	use BuildsByParameterName;

	private IAppManager&MockObject $appManager;
	private SourceBindingStore&MockObject $bindingStore;

	protected function setUp(): void {
		parent::setUp();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->bindingStore = $this->createMock(SourceBindingStore::class);

		$this->appManager->method('getAlwaysEnabledApps')->willReturn([]);
		$this->appManager->method('getEnabledApps')->willReturn(['versioniq', 'deck']);
		// Nextcloud's own record: every installed app with its installed
		// version, enabled or not. `calendar` is installed and disabled.
		$this->appManager->method('getAppInstalledVersions')->willReturnMap([
			[false, ['versioniq' => '1.0.0', 'deck' => '1.14.0', 'calendar' => '5.0.1']],
			[true, ['versioniq' => '1.0.0', 'deck' => '1.14.0']],
		]);
		$this->appManager->method('getAppVersion')->willReturnMap([
			['deck', false, '1.14.0'],
			['calendar', false, '5.0.1'],
			['hermiq', false, ''],
		]);
		$this->appManager->method('getAppPath')->willThrowException(new \OCP\App\AppPathNotFoundException('no path'));

		// `hermiq` is bound to a forge source but not installed.
		$this->bindingStore->method('listBoundAppIds')->willReturn(['hermiq', 'deck']);
		$this->bindingStore->method('get')->willReturnCallback(
			static fn (string $appId): ?SourceBinding => $appId === 'hermiq' ? SourceBinding::github('ConductionNL', 'hermiq') : null,
		);
	}

	private function service(?PublisherTrust $trust = null): InstallerService {
		return $this->buildByName(InstallerService::class, [
			'appManager' => $this->appManager,
			'config' => $this->createMock(IConfig::class),
			'appConfig' => $this->createMock(IAppConfig::class),
			'sourceRegistry' => $this->createMock(SourceRegistry::class),
			'bindingStore' => $this->bindingStore,
			'trustedSources' => $this->createMock(TrustedSourceList::class),
			'signedInstaller' => $this->createMock(SelectedReleaseInstallerService::class),
			'externalInstaller' => $this->createMock(ExternalReleaseInstallerService::class),
			'failureClassifier' => new FailureClassifier($this->createMock(IFactory::class)),
			'environmentCheck' => $this->createMock(EnvironmentCheck::class),
			'pinStore' => $this->createMock(PinStore::class),
			'userSession' => $this->createMock(IUserSession::class),
			'timeFactory' => $this->createMock(ITimeFactory::class),
			'lkgStore' => $this->createMock(LkgStore::class),
			'artifactCache' => $this->createMock(ArtifactCache::class),
			'eventDispatcher' => $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class),
			'publisherTrust' => $trust,
		]);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function appsById(): array {
		$byId = [];
		foreach ($this->service()->getInstalledApps() as $app) {
			$byId[$app['id']] = $app;
		}

		return $byId;
	}

	public function testListsEnabledDisabledAndBoundButNotInstalledAppsWithTheirState(): void {
		$apps = $this->appsById();

		self::assertSame(['calendar', 'deck', 'hermiq'], array_keys($apps));
		self::assertSame('enabled', $apps['deck']['state']);
		self::assertSame('disabled', $apps['calendar']['state']);
		self::assertSame('notInstalled', $apps['hermiq']['state']);
	}

	public function testDisabledAppKeepsItsInstalledVersionAndANotInstalledAppHasNone(): void {
		$apps = $this->appsById();

		self::assertSame('5.0.1', $apps['calendar']['installedVersion']);
		self::assertNull($apps['hermiq']['installedVersion']);
		self::assertSame('github:ConductionNL/hermiq', $apps['hermiq']['boundSourceId']);
	}

	/**
	 * Each card names the info.xml authors and carries the marks a named
	 * source backs for its binding.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public function testACardNamesThePublisherAndItsTrustMarks(): void {
		\OC_App::$allApps = [
			['id' => 'deck', 'name' => 'Deck', 'author' => [['@value' => 'Julius Härtl', '@attributes' => ['mail' => 'x@example.org']], 'Nextcloud GmbH']],
			['id' => 'hermiq', 'name' => 'Hermiq', 'author' => 'Conduction'],
		];
		$trust = $this->createMock(PublisherTrust::class);
		$trust->method('marksFor')->willReturnCallback(
			static fn (string $appId, array $binding, bool $featured): array => match ($binding['kind']) {
				'github-release' => $binding['owner'] === 'ConductionNL' && $featured === false ? ['verifiedOrg', 'trusted'] : [],
				default => $appId === 'deck' ? ['shipped'] : [],
			}
		);

		try {
			$apps = [];
			foreach ($this->service($trust)->getInstalledApps() as $app) {
				$apps[$app['id']] = $app;
			}
		} finally {
			\OC_App::$allApps = [];
		}

		self::assertSame('Julius Härtl, Nextcloud GmbH', $apps['deck']['publisher']);
		self::assertSame(['shipped'], $apps['deck']['marks']);
		self::assertSame('Conduction', $apps['hermiq']['publisher']);
		self::assertSame(['verifiedOrg', 'trusted'], $apps['hermiq']['marks']);
		self::assertNull($apps['calendar']['publisher']);
		self::assertSame([], $apps['calendar']['marks']);
	}

	public function testWithoutTheTrustServiceACardHasNoMarks(): void {
		self::assertSame([], $this->appsById()['hermiq']['marks']);
	}

	public function testVersioniqItselfIsNeverListed(): void {
		self::assertArrayNotHasKey('versioniq', $this->appsById());
	}
	/**
	 * The card carries the publisher, licence and declared links from the
	 * installed info.xml: authors joined without mail addresses, a missing
	 * link as null, and only http or https links (inventory-app-details-card).
	 */
	public function testTheCardCarriesPublisherLicenceAndSafeLinks(): void {
		\OC_App::$allApps = [
			[
				'id' => 'deck',
				'name' => 'Deck',
				'author' => 'Nextcloud',
				'licence' => 'agpl',
				'website' => 'https://github.com/nextcloud/deck',
				'repository' => ['@attributes' => ['type' => 'git'], '@value' => 'https://github.com/nextcloud/deck.git'],
				'bugs' => 'https://github.com/nextcloud/deck/issues',
			],
			[
				'id' => 'calendar',
				'name' => 'Calendar',
				'author' => [
					['@attributes' => ['mail' => 'georg@example.org', 'homepage' => 'https://example.org'], '@value' => 'Georg Ehrke'],
					['@attributes' => ['mail' => 'thomas@example.org'], '@value' => 'Thomas Citharel'],
					'Team Popcorn',
				],
				'licence' => ['agpl', 'mit'],
				'website' => 'javascript:alert(1)',
				'repository' => 'ftp://example.org/calendar',
			],
		];

		try {
			$apps = $this->appsById();
		} finally {
			\OC_App::$allApps = [];
		}

		self::assertSame('Nextcloud', $apps['deck']['author']);
		self::assertSame('agpl', $apps['deck']['licence']);
		self::assertSame(
			[
				'website' => 'https://github.com/nextcloud/deck',
				'repository' => 'https://github.com/nextcloud/deck.git',
				'bugs' => 'https://github.com/nextcloud/deck/issues',
			],
			$apps['deck']['links']
		);

		self::assertSame('Georg Ehrke, Thomas Citharel, Team Popcorn', $apps['calendar']['author']);
		self::assertStringNotContainsString('@', $apps['calendar']['author']);
		self::assertSame('agpl, mit', $apps['calendar']['licence']);
		self::assertSame(['website' => null, 'repository' => null, 'bugs' => null], $apps['calendar']['links']);

		// An app the server does not know (bound, not installed) has an empty card.
		self::assertSame('', $apps['hermiq']['author']);
		self::assertSame('', $apps['hermiq']['licence']);
		self::assertSame(['website' => null, 'repository' => null, 'bugs' => null], $apps['hermiq']['links']);
	}

	/**
	 * The card says when an app runs under an override: `forcedOn` for one
	 * Versioniq set, `compatibilityOverridden` for one someone else set, and
	 * `serverTooNew` on a disabled app only its maximum keeps off
	 * (install-force-incompatible D5).
	 */
	public function testTheCardShowsTheOverridesAndWhatMayBeEnabledAnyway(): void {
		$overrides = $this->createMock(\OCA\Versioniq\Service\Installer\ForceOverrideStore::class);
		$overrides->method('get')->willReturnCallback(
			static fn (string $appId): ?\OCA\Versioniq\Service\Installer\ForceOverride => $appId === 'deck'
				? new \OCA\Versioniq\Service\Installer\ForceOverride('1.14.0', 33, 'alice', '2026-11-01T00:00:00+00:00', true)
				: null
		);
		$overrides->method('isOverridden')->willReturnCallback(static fn (string $appId): bool => in_array($appId, ['deck', 'calendar'], true));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'version' ? '33.0.2.1' : $default);
		\OC_App::$allApps = [
			['id' => 'deck', 'name' => 'Deck', 'dependencies' => ['nextcloud' => ['@attributes' => ['min-version' => '30', 'max-version' => '32']]]],
			['id' => 'calendar', 'name' => 'Calendar', 'dependencies' => ['nextcloud' => ['@attributes' => ['min-version' => '30', 'max-version' => '32']]]],
		];

		try {
			$apps = [];
			$service = $this->buildByName(InstallerService::class, [
				'appManager' => $this->appManager,
				'config' => $config,
				'appConfig' => $this->createMock(IAppConfig::class),
				'bindingStore' => $this->bindingStore,
				'failureClassifier' => new FailureClassifier($this->createMock(IFactory::class)),
				'forceOverrides' => $overrides,
			]);
			foreach ($service->getInstalledApps() as $app) {
				$apps[$app['id']] = $app;
			}
		} finally {
			\OC_App::$allApps = [];
		}

		// Enabled, so never offered "Enable anyway", even though it declares 32.
		self::assertSame(33, $apps['deck']['forcedOn']);
		self::assertArrayNotHasKey('compatibilityOverridden', $apps['deck']);
		self::assertArrayNotHasKey('serverTooNew', $apps['deck']);
		self::assertTrue($apps['calendar']['compatibilityOverridden']);
		self::assertTrue($apps['calendar']['serverTooNew']);
		self::assertArrayNotHasKey('forcedOn', $apps['calendar']);
		self::assertSame([], array_intersect(['forcedOn', 'compatibilityOverridden', 'serverTooNew'], array_keys($apps['hermiq'])));
	}

	/**
	 * A version Versioniq put in place names that install's source; a version
	 * that differs from the record, or has none, was changed outside Versioniq.
	 *
	 * @spec openspec/specs/external-sources/spec.md#requirement-the-card-names-where-the-installed-version-came-from
	 */
	public function testProvenanceComesFromTheLastFinalizedInstall(): void {
		$record = ['version' => '1.12.0', 'recordedAt' => '2026-11-01T10:00:00+00:00', 'sourceId' => 'github:ConductionNL/pipelinq'];

		self::assertSame(
			['kind' => 'versioniq', 'sourceId' => 'github:ConductionNL/pipelinq', 'version' => '1.12.0'],
			InstallerService::provenance('1.12.0', $record),
		);
		self::assertSame(
			['kind' => 'outside', 'sourceId' => null, 'version' => '1.15.0'],
			InstallerService::provenance('1.15.0', ['version' => '1.14.1', 'recordedAt' => '2026-11-01T10:00:00+00:00', 'sourceId' => null]),
		);
		self::assertSame(['kind' => 'outside', 'sourceId' => null, 'version' => '5.0.1'], InstallerService::provenance('5.0.1', null));
		self::assertNull(InstallerService::provenance(null, $record));
	}

	public function testEveryCardCarriesItsProvenance(): void {
		$apps = $this->appsById();

		self::assertSame(['kind' => 'outside', 'sourceId' => null, 'version' => '1.14.0'], $apps['deck']['provenance']);
		self::assertNull($apps['hermiq']['provenance']);
	}
}
