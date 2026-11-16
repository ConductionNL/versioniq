<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service;

use OCA\Versioniq\Kernel\Contracts\AdvisoryDigestNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryResultStoreInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryServiceInterface;
use OCA\Versioniq\Kernel\Contracts\AppStoreSourceInterface;
use OCA\Versioniq\Kernel\Contracts\AvailabilityResultStoreInterface;
use OCA\Versioniq\Kernel\Contracts\AvailabilityServiceInterface;
use OCA\Versioniq\Kernel\Contracts\FailureClassifierInterface;
use OCA\Versioniq\Kernel\Contracts\ForgeRegistryInterface;
use OCA\Versioniq\Kernel\Contracts\InstallerServiceInterface;
use OCA\Versioniq\Kernel\Contracts\InstanceSettingsInterface;
use OCA\Versioniq\Kernel\Contracts\ManifestBuilderInterface;
use OCA\Versioniq\Kernel\Contracts\PatExpiryEvaluatorInterface;
use OCA\Versioniq\Kernel\Contracts\PinDriftHandlerInterface;
use OCA\Versioniq\Kernel\Contracts\ReleaseFeedInterface;
use OCA\Versioniq\Kernel\Contracts\ReleaseSweepRecorderInterface;
use OCA\Versioniq\Kernel\Contracts\ServerEolWarnerInterface;
use OCA\Versioniq\Kernel\Contracts\WebhookSenderInterface;
use OCA\Versioniq\Kernel\Contracts\WebhookTargetStoreInterface;
use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Alert\WebhookSender;
use OCA\Versioniq\Service\Alert\WebhookTargetStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\ContractBindings;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Instance\ManifestBuilder;
use OCA\Versioniq\Service\Lifecycle\ServerEolWarner;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseFeed;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseSweepRecorder;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for ContractBindings: every contract is registered once as an
 * alias of the service that implements it, and the constants a contract took
 * over still read the same through the service.
 *
 * @covers \OCA\Versioniq\Service\ContractBindings
 */
final class ContractBindingsTest extends TestCase {
	/**
	 * The expected contract of each service.
	 *
	 * @var array<class-string, class-string>
	 */
	private const EXPECTED = [
		AdvisoryDigestNotifierInterface::class => AdvisoryDigestNotifier::class,
		AdvisoryNotifierInterface::class => AdvisoryNotifier::class,
		AdvisoryResultStoreInterface::class => AdvisoryResultStore::class,
		AdvisoryServiceInterface::class => AdvisoryService::class,
		AvailabilityResultStoreInterface::class => AvailabilityResultStore::class,
		AvailabilityServiceInterface::class => AvailabilityService::class,
		ForgeRegistryInterface::class => ForgeRegistry::class,
		InstallerServiceInterface::class => InstallerService::class,
		InstanceSettingsInterface::class => InstanceSettings::class,
		ManifestBuilderInterface::class => ManifestBuilder::class,
		PatExpiryEvaluatorInterface::class => PatExpiryEvaluator::class,
		PinDriftHandlerInterface::class => PinDriftHandler::class,
		ReleaseFeedInterface::class => ReleaseFeed::class,
		ReleaseSweepRecorderInterface::class => ReleaseSweepRecorder::class,
		ServerEolWarnerInterface::class => ServerEolWarner::class,
		WebhookSenderInterface::class => WebhookSender::class,
		WebhookTargetStoreInterface::class => WebhookTargetStore::class,
	];

	public function testEveryContractIsAliasedToItsService(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(count(self::EXPECTED)))
			->method('registerServiceAlias')
			->willReturnCallback(
				function (string $alias, string $target) use (&$aliases): void {
					$aliases[$alias] = $target;
				},
			);

		(new ContractBindings())->register($context);

		$this->assertSame(self::EXPECTED, $aliases);
	}

	public function testEveryServiceImplementsItsContract(): void {
		foreach (self::EXPECTED as $contract => $service) {
			$this->assertTrue(interface_exists($contract), $contract . ' is not an interface');
			$this->assertContains($contract, class_implements($service), $service . ' does not implement ' . $contract);
		}
	}

	public function testTheMovedConstantsKeepTheirValues(): void {
		$this->assertSame('none', AdvisoryService::STATE_NONE);
		$this->assertSame('advisory-available', AdvisoryService::STATE_AVAILABLE);
		$this->assertSame('https://gitlab.com', ForgeRegistry::GITLAB_WEB_DEFAULT);
		$this->assertSame('appstore.payload.', AppStoreSource::PAYLOAD_CACHE_PREFIX);
		$this->assertSame('appstore.payload_ts.', AppStoreSource::PAYLOAD_CACHE_TS_PREFIX);
		$this->assertSame('downgrade_guard', FailureClassifier::CATEGORY_DOWNGRADE_GUARD);
		$this->assertSame('preflight_permission', FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION);
		$this->assertSame('unknown', FailureClassifier::CATEGORY_UNKNOWN);
	}

	public function testTheConstantContractsAreImplementedByTheirOwners(): void {
		$this->assertContains(AppStoreSourceInterface::class, class_implements(AppStoreSource::class));
		$this->assertContains(FailureClassifierInterface::class, class_implements(FailureClassifier::class));
		$this->assertSame([], (new \ReflectionClass(AppStoreSourceInterface::class))->getMethods());
		$this->assertSame([], (new \ReflectionClass(FailureClassifierInterface::class))->getMethods());
	}
}
