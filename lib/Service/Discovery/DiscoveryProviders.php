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
 * The discovery providers, in the order the aggregator asks them: the App
 * Store, private GitHub repositories, then public GitHub search.
 *
 * @psalm-api
 * @spec openspec/specs/app-discovery/spec.md
 */
final class DiscoveryProviders {
	public function __construct(
		public readonly AppStoreDiscovery $appStore,
		public readonly GithubPrivateDiscovery $githubPrivate,
		public readonly GithubSearchDiscovery $githubSearch,
	) {
	}

	/**
	 * The providers as a list, in asking order.
	 *
	 * @return list<DiscoveryProviderInterface>
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public function all(): array {
		return [$this->appStore, $this->githubPrivate, $this->githubSearch];
	}
}
