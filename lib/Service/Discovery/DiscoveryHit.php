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

/**
 * One result from a discovery provider.
 *
 * `appId` may be a best-effort guess (e.g. derived from the GitHub repo name)
 * for sources that don't explicitly publish an app id; downstream code should
 * not rely on uniqueness of `appId` across providers, only within a provider's
 * own response.
 */
final class DiscoveryHit {
	public readonly string $name;
	public readonly string $summary;
	public readonly ?string $iconUrl;
	public readonly string $sourceProviderId;
	/** @var array<string, mixed> */
	public readonly array $sourceBinding;
	public readonly bool $installable;
	public readonly ?string $installableReason;
	public readonly ?string $homepageUrl;
	public readonly ?string $publisher;
	/** @var list<string> */
	public readonly array $marks;

	public function __construct(
		public readonly string $appId,
		DiscoveryListing $listing,
		DiscoverySource $source,
	) {
		$this->name = $listing->name;
		$this->summary = $listing->summary;
		$this->iconUrl = $listing->iconUrl;
		$this->sourceProviderId = $source->providerId;
		$this->sourceBinding = $source->binding;
		$this->installable = $source->installable;
		$this->installableReason = $source->installableReason;
		$this->homepageUrl = $listing->homepageUrl;
		$this->publisher = $listing->publisher;
		$this->marks = $listing->featured ? [PublisherTrust::MARK_FEATURED] : [];
	}

	/**
	 * Serializes a discovery hit into the uniform result shape; see "Result aggregation".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 * @psalm-api
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'appId' => $this->appId,
			'name' => $this->name,
			'summary' => $this->summary,
			'iconUrl' => $this->iconUrl,
			'sourceProviderId' => $this->sourceProviderId,
			'sourceBinding' => $this->sourceBinding,
			'installable' => $this->installable,
			'installableReason' => $this->installableReason,
			'homepageUrl' => $this->homepageUrl,
			'publisher' => $this->publisher,
			'marks' => $this->marks,
		];
	}
}
