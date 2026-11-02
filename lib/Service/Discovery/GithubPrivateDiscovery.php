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
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Searches for installable apps in private GitHub repos that the current
 * admin's PATs can see. Each PAT scopes the search to its `target_pattern`
 * via the GitHub Search API's `org:` / `repo:` qualifier.
 *
 * Implementation note: instead of `/search/code` (slow + costly), we use
 * `/search/repositories` with a topic filter. Repos that don't yet have
 * the `nextcloud-app` topic but DO match the query string still surface;
 * the `installable` flag only flips false when the repo is outside the
 * trusted-source allowlist.
 */
class GithubPrivateDiscovery implements DiscoveryProviderInterface {
	use MapsGithubRepositories;

	public const ID = 'github-private';
	private const UNTRUSTED_REASON = 'Add `%s/*` to the trusted-source allowlist to install from this repo.';

	/**
	 * @psalm-api
	 */
	public function __construct(
		private PatMapper $patMapper,
		private PatManager $patManager,
		private TrustedSourceList $trustedSources,
		private IUserSession $userSession,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'GitHub (private, your PATs)';
	}

	/**
	 * Enabled only when the current admin has at least one visible PAT; see "GitHub private discovery".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function isEnabled(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->patMapper->findVisibleTo($user->getUID()) !== [];
	}

	/**
	 * Searches PAT-visible private GitHub repos, deduped by owner/repo; see "GitHub private discovery".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function search(string $query): DiscoveryResult {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return DiscoveryResult::failed('Not authenticated.');
		}

		$pats = $this->patMapper->findVisibleTo($user->getUID());
		if ($pats === []) {
			return DiscoveryResult::empty();
		}

		$hits = [];
		$lastError = null;
		foreach ($pats as $pat) {
			try {
				$patHits = $this->searchOne($pat, $query);
				$hits = array_merge($hits, $patHits);
			} catch (Exception $error) {
				$this->logger->warning('GithubPrivateDiscovery: search failed for PAT', [
					'patId' => $pat->getId(),
					'errorMessage' => $error->getMessage(),
				]);
				$lastError = 'One or more PAT-scoped searches failed (' . $error->getMessage() . ').';
			}
		}

		// De-duplicate by owner/repo within this provider's results.
		$seen = [];
		$unique = [];
		foreach ($hits as $hit) {
			$key = (string)($hit->sourceBinding['owner'] ?? '') . '/' . (string)($hit->sourceBinding['repo'] ?? '');
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$unique[] = $hit;
		}

		return new DiscoveryResult($unique, $unique === [] ? $lastError : null);
	}

	/**
	 * @return list<DiscoveryHit>
	 */
	private function searchOne(Pat $pat, string $query): array {
		$scopeClause = $this->buildScopeClause($pat->getTargetPattern());
		if ($scopeClause === null) {
			return [];
		}

		$url = $this->searchUrl(trim($query) . ' ' . $scopeClause . ' fork:false');
		$items = $this->patManager->useToken($pat, fn (string $token): ?array => $this->fetchItems($url, $token));

		return $items === null ? [] : $this->repoHits($items);
	}

	/**
	 * The result items of one authenticated search, or null when the request
	 * fails, answers other than 200, or returns malformed JSON or no `items`
	 * list. An error reading the status or the body propagates.
	 *
	 * @return list<array<array-key, mixed>>|null
	 */
	private function fetchItems(
		string $url,
		#[\SensitiveParameter]
		string $token,
	): ?array {
		$response = $this->send($url, $token);
		if ($response === null || $response->getStatusCode() !== 200) {
			return null;
		}

		try {
			return $this->decodeItems((string)$response->getBody());
		} catch (\JsonException) {
			return null;
		}
	}

	/**
	 * The response of one authenticated search request, or null when the request fails.
	 */
	private function send(
		string $url,
		#[\SensitiveParameter]
		string $token,
	): ?IResponse {
		try {
			return $this->clientService->newClient()->get($url, $this->searchOptions(['Authorization' => 'Bearer ' . $token]));
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Maps a `target_pattern` glob to a GitHub Search qualifier.
	 *   ConductionNL/*           → org:ConductionNL
	 *   ConductionNL/openregister → repo:ConductionNL/openregister
	 *   *                         → null (impossible to scope safely)
	 */
	private function buildScopeClause(string $pattern): ?string {
		$pattern = trim($pattern);
		if ($pattern === '' || $pattern === '*' || $pattern === '*/*') {
			return null;
		}

		if (str_ends_with($pattern, '/*')) {
			$owner = substr($pattern, 0, -2);
			if ($owner === '' || str_contains($owner, '*')) {
				return null;
			}

			return 'org:' . $owner . ' user:' . $owner;
		}

		if (str_contains($pattern, '/') && !str_contains($pattern, '*')) {
			return 'repo:' . $pattern;
		}

		return null;
	}
}
