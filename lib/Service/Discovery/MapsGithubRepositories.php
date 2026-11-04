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

use OCA\Versioniq\Service\Source\SourceBinding;

/**
 * The GitHub repository search request and the mapping of its result items
 * to discovery hits, shared by the public and the PAT-scoped search.
 *
 * The using class has the properties `$trustedSources` (TrustedSourceList)
 * and the constants ID (the provider id) and UNTRUSTED_REASON (a sprintf
 * format taking the repository owner).
 */
trait MapsGithubRepositories {
	/**
	 * The repository search URL for a GitHub search query, 30 results per page.
	 */
	private function searchUrl(string $q): string {
		return 'https://api.github.com/search/repositories?' . http_build_query(['q' => $q, 'per_page' => 30]);
	}

	/**
	 * The HTTP client options of a search request; `$headers` go first.
	 *
	 * @param array<string, string> $headers
	 * @return array{headers: array<string, string>, timeout: int, http_errors: bool}
	 */
	private function searchOptions(array $headers): array {
		return [
			'headers' => $headers + [
				'Accept' => 'application/vnd.github+json',
				'User-Agent' => 'Nextcloud-Versioniq',
				'X-GitHub-Api-Version' => '2022-11-28',
			],
			'timeout' => 30,
			'http_errors' => false,
		];
	}

	/**
	 * The array entries of a search response's `items`, or null when the body
	 * has no `items` list.
	 *
	 * @return list<array<array-key, mixed>>|null
	 * @throws \JsonException When the body is not valid JSON.
	 */
	private function decodeItems(string $body): ?array {
		/** @var mixed $decoded */
		$decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
			return null;
		}

		return array_values(array_filter($decoded['items'], 'is_array'));
	}

	/**
	 * @param list<array<array-key, mixed>> $items
	 * @return list<DiscoveryHit>
	 */
	private function repoHits(array $items): array {
		$hits = [];
		foreach ($items as $repo) {
			$hit = $this->repoHit($repo);
			if ($hit !== null) {
				$hits[] = $hit;
			}
		}

		return $hits;
	}

	/**
	 * The hit for one repository, or null when its `full_name` is not an `owner/repo` string.
	 *
	 * @param array<array-key, mixed> $repo
	 */
	private function repoHit(array $repo): ?DiscoveryHit {
		$fullName = $this->stringField($repo, 'full_name') ?? '';
		if (!str_contains($fullName, '/')) {
			return null;
		}
		$parts = explode('/', $fullName, 2);

		return $this->hitFor($repo, [
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => $parts[0],
			'repo' => $parts[1] ?? '',
		]);
	}

	/**
	 * @param array<array-key, mixed> $repo
	 * @param array{kind: string, owner: string, repo: string} $binding
	 */
	private function hitFor(array $repo, array $binding): DiscoveryHit {
		$installable = $this->trustedSources->isAllowed('github:' . $binding['owner'] . '/' . $binding['repo']);

		return new DiscoveryHit(
			appId: strtolower(str_replace('-', '_', $binding['repo'])),
			listing: new DiscoveryListing(
				name: $this->stringField($repo, 'name') ?? $binding['repo'],
				summary: $this->stringField($repo, 'description') ?? '',
				iconUrl: null,
				homepageUrl: $this->stringField($repo, 'html_url'),
			),
			source: new DiscoverySource(
				providerId: self::ID,
				binding: $binding,
				installable: $installable,
				installableReason: $installable ? null : sprintf(self::UNTRUSTED_REASON, $binding['owner']),
			),
		);
	}

	/**
	 * @param array<array-key, mixed> $repo
	 */
	private function stringField(array $repo, string $key): ?string {
		/** @var mixed $value */
		$value = $repo[$key] ?? null;

		return is_string($value) ? $value : null;
	}
}
