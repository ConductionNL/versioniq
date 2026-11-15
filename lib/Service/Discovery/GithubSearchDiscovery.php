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
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Opt-in public GitHub repository search restricted to repos with the
 * `nextcloud-app` topic OR matching the query in name/description. Disabled
 * by default; flips on via `versioniq.discovery.github_search_enabled`.
 *
 * Sends every search query to GitHub. Admin must opt in consciously.
 */
class GithubSearchDiscovery implements DiscoveryProviderInterface {
	use MapsGithubRepositories;

	public const ID = 'github-search';
	private const ENABLED_KEY = 'discovery.github_search_enabled';
	private const UNTRUSTED_REASON = 'Add `%s/*` to the trusted-source allowlist to install this app.';

	/**
	 * @psalm-api
	 */
	public function __construct(
		private IAppConfig $config,
		private TrustedSourceList $trustedSources,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getLabel(): string {
		return 'GitHub (public search)';
	}

	public function isEnabled(): bool {
		return $this->config->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false') === 'true';
	}

	/**
	 * Opt-in public GitHub topic search with rate-limit handling; see "GitHub public search (opt-in)".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function search(string $query): DiscoveryResult {
		if (!$this->isEnabled() || trim($query) === '') {
			return DiscoveryResult::empty();
		}

		try {
			$response = $this->clientService->newClient()->get(
				$this->searchUrl(trim($query) . ' topic:nextcloud-app fork:false'),
				$this->searchOptions([]),
			);
		} catch (Exception $error) {
			$this->logger->warning('GithubSearchDiscovery: search failed', ['errorMessage' => $error->getMessage()]);

			return DiscoveryResult::failed('Could not reach GitHub search.');
		}

		return $this->toResult($response);
	}

	/**
	 * The result for a search response: an error for a non-200 status or a
	 * malformed body, no hits when the body has no `items` list.
	 */
	private function toResult(IResponse $response): DiscoveryResult {
		$status = $response->getStatusCode();
		if ($status !== 200) {
			return DiscoveryResult::failed($status === 403
				? 'GitHub search rate limit exceeded — try again later.'
				: sprintf('GitHub search returned HTTP %d.', $status));
		}

		try {
			$items = $this->decodeItems((string)$response->getBody());
		} catch (\JsonException) {
			return DiscoveryResult::failed('GitHub search returned malformed JSON.');
		}

		return $items === null ? DiscoveryResult::empty() : new DiscoveryResult($this->repoHits($items), null);
	}
}
