<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Discovery;

/**
 * Merges the provider results of one discovery search: hits are grouped by
 * app id with every provider's source as a candidate, App Store metadata
 * wins over other providers', and provider errors are collected.
 *
 * @spec openspec/specs/app-discovery/spec.md
 * @psalm-type Group = array{appId: string, name: string, summary: string, iconUrl: ?string, homepageUrl: ?string, installedVersion: ?string, sourceCandidates: list<array{providerId: string, sourceBinding: array<string, mixed>, installable: bool, installableReason: ?string}>}
 */
final class DiscoveryMerge {
	/** @var array<string, Group> */
	private array $grouped = [];

	/** @var list<array{providerId: string, message: string}> */
	private array $errors = [];

	/**
	 * @param array<string, string> $installed Installed version per enabled app id.
	 */
	public function __construct(
		private array $installed,
	) {
	}

	/**
	 * Adds one provider's result: its error, if any, then each of its hits; see "Result aggregation".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function add(string $providerId, DiscoveryResult $result): void {
		if ($result->error !== null) {
			$this->errors[] = ['providerId' => $providerId, 'message' => $result->error];
		}

		foreach ($result->hits as $hit) {
			$this->addHit($hit);
		}
	}

	/**
	 * The merged results, installed apps first, then by name; see "Result aggregation".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 * @return list<Group>
	 */
	public function results(bool $installedOnly): array {
		$results = array_values($this->grouped);
		if ($installedOnly) {
			$results = array_values(array_filter($results, static fn (array $r): bool => $r['installedVersion'] !== null));
		}

		usort($results, self::compare(...));

		return $results;
	}

	/**
	 * The provider errors in provider order; see "Result aggregation".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 * @return list<array{providerId: string, message: string}>
	 */
	public function errors(): array {
		return $this->errors;
	}

	private function addHit(DiscoveryHit $hit): void {
		$this->grouped[$hit->appId] ??= $this->newGroup($hit);

		if ($hit->sourceProviderId === AppStoreDiscovery::ID) {
			$this->preferAppStoreMetadata($hit);
		}

		$this->grouped[$hit->appId]['sourceCandidates'][] = [
			'providerId' => $hit->sourceProviderId,
			'sourceBinding' => $hit->sourceBinding,
			'installable' => $hit->installable,
			'installableReason' => $hit->installableReason,
		];
	}

	/**
	 * @return Group
	 */
	private function newGroup(DiscoveryHit $hit): array {
		return [
			'appId' => $hit->appId,
			'name' => $hit->name,
			'summary' => $hit->summary,
			'iconUrl' => $hit->iconUrl,
			'homepageUrl' => $hit->homepageUrl,
			'installedVersion' => $this->installed[$hit->appId] ?? null,
			'sourceCandidates' => [],
		];
	}

	/**
	 * Takes the App Store summary and icon when present, never overwriting with an empty value.
	 */
	private function preferAppStoreMetadata(DiscoveryHit $hit): void {
		if ($hit->summary !== '') {
			$this->grouped[$hit->appId]['summary'] = $hit->summary;
		}
		if ($hit->iconUrl !== null) {
			$this->grouped[$hit->appId]['iconUrl'] = $hit->iconUrl;
		}
	}

	/**
	 * @param Group $a
	 * @param Group $b
	 */
	private static function compare(array $a, array $b): int {
		$aInstalled = $a['installedVersion'] !== null ? 1 : 0;
		$bInstalled = $b['installedVersion'] !== null ? 1 : 0;
		if ($aInstalled !== $bInstalled) {
			return $bInstalled - $aInstalled;
		}

		return strcmp($a['name'], $b['name']);
	}
}
