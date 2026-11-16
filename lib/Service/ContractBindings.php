<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service;

use OCA\Versioniq\Kernel\Contracts\AdvisoryDigestNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryResultStoreInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisoryServiceInterface;
use OCA\Versioniq\Kernel\Contracts\AdvisorySettingsStoreInterface;
use OCA\Versioniq\Kernel\Contracts\AttemptLedgerInterface;
use OCA\Versioniq\Kernel\Contracts\AutoUpdateNotifierInterface;
use OCA\Versioniq\Kernel\Contracts\AvailabilityResultStoreInterface;
use OCA\Versioniq\Kernel\Contracts\AvailabilityServiceInterface;
use OCA\Versioniq\Kernel\Contracts\ForgeRegistryInterface;
use OCA\Versioniq\Kernel\Contracts\InstallerServiceInterface;
use OCA\Versioniq\Kernel\Contracts\InstanceSettingsInterface;
use OCA\Versioniq\Kernel\Contracts\ManifestBuilderInterface;
use OCA\Versioniq\Kernel\Contracts\PatExpiryEvaluatorInterface;
use OCA\Versioniq\Kernel\Contracts\PinDriftHandlerInterface;
use OCA\Versioniq\Kernel\Contracts\PinStoreInterface;
use OCA\Versioniq\Kernel\Contracts\ReleaseFeedInterface;
use OCA\Versioniq\Kernel\Contracts\ReleaseSweepRecorderInterface;
use OCA\Versioniq\Kernel\Contracts\ServerEolWarnerInterface;
use OCA\Versioniq\Kernel\Contracts\WebhookSenderInterface;
use OCA\Versioniq\Kernel\Contracts\WebhookTargetStoreInterface;
use OCA\Versioniq\Service\Advisory\AdvisoryDigestNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryNotifier;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisoryService;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\Alert\WebhookSender;
use OCA\Versioniq\Service\Alert\WebhookTargetStore;
use OCA\Versioniq\Service\AutoUpdate\AttemptLedger;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateNotifier;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\Availability\AvailabilityService;
use OCA\Versioniq\Service\Instance\ManifestBuilder;
use OCA\Versioniq\Service\Lifecycle\ServerEolWarner;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pin\PinDriftHandler;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseFeed;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseSweepRecorder;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds each contract in Kernel\Contracts to the service that implements it,
 * so the container hands the implementation to a constructor typed with the
 * contract.
 *
 * @spec exclude Structural wiring, no functional requirement.
 */
final class ContractBindings {
	/**
	 * The contract of each service, by contract.
	 *
	 * @var array<class-string, class-string>
	 */
	private const BINDINGS = [
		AdvisoryDigestNotifierInterface::class => AdvisoryDigestNotifier::class,
		AdvisoryNotifierInterface::class => AdvisoryNotifier::class,
		AdvisoryResultStoreInterface::class => AdvisoryResultStore::class,
		AdvisoryServiceInterface::class => AdvisoryService::class,
		AdvisorySettingsStoreInterface::class => AdvisorySettingsStore::class,
		AttemptLedgerInterface::class => AttemptLedger::class,
		AutoUpdateNotifierInterface::class => AutoUpdateNotifier::class,
		AvailabilityResultStoreInterface::class => AvailabilityResultStore::class,
		AvailabilityServiceInterface::class => AvailabilityService::class,
		ForgeRegistryInterface::class => ForgeRegistry::class,
		InstallerServiceInterface::class => InstallerService::class,
		InstanceSettingsInterface::class => InstanceSettings::class,
		ManifestBuilderInterface::class => ManifestBuilder::class,
		PatExpiryEvaluatorInterface::class => PatExpiryEvaluator::class,
		PinDriftHandlerInterface::class => PinDriftHandler::class,
		PinStoreInterface::class => PinStore::class,
		ReleaseFeedInterface::class => ReleaseFeed::class,
		ReleaseSweepRecorderInterface::class => ReleaseSweepRecorder::class,
		ServerEolWarnerInterface::class => ServerEolWarner::class,
		WebhookSenderInterface::class => WebhookSender::class,
		WebhookTargetStoreInterface::class => WebhookTargetStore::class,
	];

	/**
	 * Registers every binding as a service alias.
	 *
	 * @spec exclude Structural wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}
}
