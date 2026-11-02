<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Discovery;

use Exception;
use OCP\App\IAppManager;

/**
 * Runs every enabled discovery provider for a query, de-duplicates hits by
 * `appId`, and emits a uniform result the controller turns into a JSON
 * response.
 *
 * The aggregator is intentionally simple: synchronous PHP iteration over
 * providers. If a provider becomes too slow we swap in `IClient::getMulti`
 * later — for now total wall time is acceptable for an admin-facing search.
 */
class DiscoveryAggregator {
	/** @var list<DiscoveryProviderInterface> */
	private array $providers;

	/**
	 * @psalm-api
	 */
	public function __construct(
		private IAppManager $appManager,
		AppStoreDiscovery $appStore,
		GithubPrivateDiscovery $githubPrivate,
		GithubSearchDiscovery $githubSearch,
	) {
		$this->providers = [$appStore, $githubPrivate, $githubSearch];
	}

	/**
	 * Lists every provider with its enabled state; see "Provider interface".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 * @return list<array{id: string, label: string, enabled: bool}>
	 */
	public function listProviders(): array {
		return array_map(
			static fn (DiscoveryProviderInterface $p): array => [
				'id' => $p->getId(),
				'label' => $p->getLabel(),
				'enabled' => $p->isEnabled(),
			],
			$this->providers
		);
	}

	/**
	 * Runs active providers, de-duplicates hits by appId, annotates installed versions;
	 * see "Result aggregation".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 * @return array{results: list<array<string, mixed>>, providers: list<array{id: string, label: string, enabled: bool}>, errors: list<array{providerId: string, message: string}>}
	 */
	public function search(string $query, DiscoveryFilter $filter = new DiscoveryFilter()): array {
		$active = $this->resolveActive($filter->sourceIds);
		$merge = new DiscoveryMerge($this->snapshotInstalled());

		foreach ($active as $provider) {
			$merge->add($provider->getId(), $this->runProvider($provider, $query));
		}

		return [
			'results' => $merge->results($filter->installedOnly),
			'providers' => $this->listProviders(),
			'errors' => $merge->errors(),
		];
	}

	/**
	 * The provider's result, or a failed result carrying the message when it throws.
	 */
	private function runProvider(DiscoveryProviderInterface $provider, string $query): DiscoveryResult {
		try {
			return $provider->search($query);
		} catch (Exception $error) {
			return DiscoveryResult::failed($error->getMessage());
		}
	}

	/**
	 * @param list<string>|null $sourceIds
	 * @return list<DiscoveryProviderInterface>
	 */
	private function resolveActive(?array $sourceIds): array {
		if ($sourceIds === null || $sourceIds === []) {
			return array_values(array_filter($this->providers, static fn (DiscoveryProviderInterface $p): bool => $p->isEnabled()));
		}

		$wanted = array_flip($sourceIds);

		return array_values(array_filter(
			$this->providers,
			static fn (DiscoveryProviderInterface $p): bool => isset($wanted[$p->getId()]) && $p->isEnabled()
		));
	}

	/**
	 * @return array<string, string>
	 */
	private function snapshotInstalled(): array {
		$installed = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			try {
				$installed[$appId] = $this->appManager->getAppVersion($appId);
			} catch (Exception) {
				$installed[$appId] = '';
			}
		}

		return $installed;
	}
}
