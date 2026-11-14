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
 *
 * @psalm-import-type Group from DiscoveryMerge
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
		private ?PublisherTrust $trust = null,
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
			'results' => self::withSimilarNames($this->withTrustMarks($merge->results($filter->installedOnly))),
			'providers' => $this->listProviders(),
			'errors' => $merge->errors(),
		];
	}

	/**
	 * Gives each result every trust mark a named source backs for any of its
	 * source candidates; see "Hits and cards name the publisher".
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 * @param list<Group> $results
	 * @return list<Group>
	 */
	private function withTrustMarks(array $results): array {
		if ($this->trust === null) {
			return $results;
		}

		foreach ($results as $index => $result) {
			$featured = in_array(PublisherTrust::MARK_FEATURED, $result['marks'], true);
			$marks = $result['marks'];
			foreach ($result['sourceCandidates'] as $candidate) {
				$marks = [...$marks, ...$this->trust->marksFor($result['appId'], $candidate['sourceBinding'], $featured)];
			}
			$results[$index]['marks'] = array_values(array_intersect(PublisherTrust::MARKS, $marks));
		}

		return array_values($results);
	}

	/**
	 * Gives every result whose normalised name it shares with a result from
	 * another publisher a `similarTo` list naming those results; see "Hits
	 * and cards name the publisher". The name is lower-cased, spaces, dashes
	 * and underscores are removed, and a trailing "app" is dropped.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 * @param list<Group> $results
	 * @return list<Group>
	 */
	public static function withSimilarNames(array $results): array {
		$byName = [];
		foreach ($results as $index => $result) {
			$byName[self::normalisedName($result['name'])][] = $index;
		}

		foreach ($results as $index => $result) {
			$similar = [];
			foreach ($byName[self::normalisedName($result['name'])] as $other) {
				$candidate = $results[$other];
				if ($other === $index || self::samePublisher($result['publisher'], $candidate['publisher'])) {
					continue;
				}
				$similar[] = ['appId' => $candidate['appId'], 'name' => $candidate['name'], 'publisher' => $candidate['publisher']];
			}
			$results[$index]['similarTo'] = $similar;
		}

		return array_values($results);
	}

	/**
	 * The comparable form of an app name.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public static function normalisedName(string $name): string {
		$plain = (string)preg_replace('/[\s_-]+/u', '', mb_strtolower($name));
		if (strlen($plain) > 3 && str_ends_with($plain, 'app')) {
			$plain = substr($plain, 0, -3);
		}

		return $plain;
	}

	/**
	 * Two publishers are the same only when both are known and equal, ignoring case.
	 */
	private static function samePublisher(?string $left, ?string $right): bool {
		return $left !== null && $right !== null && mb_strtolower(trim($left)) === mb_strtolower(trim($right));
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
