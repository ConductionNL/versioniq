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
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;

/**
 * Who publishes an app, and the trust marks a named source backs.
 *
 * Each mark comes from one source of truth and nothing else: `shipped` from
 * the server (`IAppManager::isShipped()`), `featured` from the App Store's
 * own flag, `verifiedOrg` from GitHub's `is_verified` on the owning
 * organisation, and `trusted` from the admin's trusted-source allowlist.
 * Versioniq ships no list of its own: a mark it cannot back is not shown.
 *
 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md
 */
class PublisherTrust {
	public const MARK_SHIPPED = 'shipped';
	public const MARK_FEATURED = 'featured';
	public const MARK_VERIFIED_ORG = 'verifiedOrg';
	public const MARK_TRUSTED = 'trusted';

	/** The marks in display order. */
	public const MARKS = [self::MARK_SHIPPED, self::MARK_FEATURED, self::MARK_VERIFIED_ORG, self::MARK_TRUSTED];

	/** App config key prefix of the per-owner GitHub organisation answer. */
	public const CACHE_PREFIX = PublisherTrustCache::CACHE_PREFIX;

	/** How long a GitHub organisation answer is reused: one week. */
	public const CACHE_TTL_SECONDS = PublisherTrustCache::CACHE_TTL_SECONDS;

	/** A GitHub login: letters, digits and single dashes, at most 39 characters. */
	private const OWNER_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/';

	/** @var array<string, bool> Answers already read in this request. */
	private array $verified = [];

	/**
	 * @psalm-api
	 */
	public function __construct(
		private IAppManager $appManager,
		private TrustedSourceList $trustedSources,
		private GithubOrganisationLookup $github,
		private PublisherTrustCache $cache,
	) {
	}

	/**
	 * The marks an app earns, in display order.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 * @param array<string, mixed> $binding The source binding of the hit or card.
	 * @param bool $featured The App Store's featured flag for the app.
	 * @return list<string>
	 */
	public function marksFor(string $appId, array $binding, bool $featured): array {
		return [...$this->ownMarks($appId, $featured), ...$this->forgeMarks(self::forgeSourceId($binding))];
	}

	/**
	 * Whether GitHub reports the owner as an organisation with a verified
	 * domain. The answer is cached per owner for a week; a personal account
	 * (404 on the organisations endpoint) is cached as not verified. A failed
	 * call counts as not verified and is not cached, so it is asked again.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public function isVerifiedOrganisation(string $owner): bool {
		if (preg_match(self::OWNER_PATTERN, $owner) !== 1) {
			return false;
		}

		$key = strtolower($owner);
		if (!array_key_exists($key, $this->verified)) {
			$this->verified[$key] = $this->answerFor($owner, $key);
		}

		return $this->verified[$key];
	}

	/**
	 * The publisher line from App Store authors: their names joined, or null.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public static function publisherFromAuthors(mixed $authors): ?string {
		$names = array_column(self::authorNames($authors), 'name');

		return $names === [] ? null : implode(', ', $names);
	}

	/**
	 * App Store or info.xml authors reduced to their names. The catalogue
	 * gives a list of `{name, mail, homepage}` entries or one such entry, the
	 * server's app info a string, a list of strings or `{@value, @attributes}`
	 * entries; every field but the name is dropped.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 * @return list<array{name: string}>
	 */
	public static function authorNames(mixed $authors): array {
		$names = [];
		/** @var mixed $author */
		foreach (self::authorEntries($authors) as $author) {
			$name = self::authorName($author);
			if ($name !== null) {
				$names[] = ['name' => $name];
			}
		}

		return $names;
	}

	/**
	 * The author entries as a list: one string or one entry becomes a list of one.
	 *
	 * @return array<array-key, mixed>
	 */
	private static function authorEntries(mixed $authors): array {
		if (!is_array($authors)) {
			return [$authors];
		}

		return isset($authors['name']) || isset($authors['@value']) ? [$authors] : $authors;
	}

	/**
	 * One author's trimmed name, or null when it has none.
	 */
	private static function authorName(mixed $author): ?string {
		/** @var mixed $name */
		$name = is_array($author) ? ($author['name'] ?? $author['@value'] ?? null) : $author;

		return is_string($name) && trim($name) !== '' ? trim($name) : null;
	}

	/**
	 * The shipped and featured marks.
	 *
	 * @return list<string>
	 */
	private function ownMarks(string $appId, bool $featured): array {
		$marks = [];
		if ($this->isShipped($appId)) {
			$marks[] = self::MARK_SHIPPED;
		}
		if ($featured) {
			$marks[] = self::MARK_FEATURED;
		}

		return $marks;
	}

	/**
	 * The verified-organisation and trusted-source marks of a forge source.
	 *
	 * @param array{forge: string, owner: string, id: string}|null $forgeSource
	 * @return list<string>
	 */
	private function forgeMarks(?array $forgeSource): array {
		if ($forgeSource === null) {
			return [];
		}

		$marks = [];
		if ($forgeSource['forge'] === SourceBinding::FORGE_GITHUB && $this->isVerifiedOrganisation($forgeSource['owner'])) {
			$marks[] = self::MARK_VERIFIED_ORG;
		}
		if ($this->trustedSources->isAllowed($forgeSource['id'])) {
			$marks[] = self::MARK_TRUSTED;
		}

		return $marks;
	}

	/**
	 * The forge, owner and source id (`<forge>:<owner>/<repo>`) of a forge
	 * release binding, or null for any other source.
	 *
	 * @param array<string, mixed> $binding
	 * @return array{forge: string, owner: string, id: string}|null
	 */
	private static function forgeSourceId(array $binding): ?array {
		$owner = self::filled($binding['owner'] ?? null);
		$repo = self::filled($binding['repo'] ?? null);
		if (($binding['kind'] ?? null) !== SourceBinding::KIND_GITHUB_RELEASE || $owner === null || $repo === null) {
			return null;
		}
		$forge = self::filled($binding['forge'] ?? null) ?? SourceBinding::FORGE_GITHUB;

		return ['forge' => $forge, 'owner' => $owner, 'id' => $forge . ':' . $owner . '/' . $repo];
	}

	/**
	 * A non-empty string, or null.
	 */
	private static function filled(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * The cached answer, else GitHub's, cached; false when GitHub could not be asked.
	 */
	private function answerFor(string $owner, string $key): bool {
		$cached = $this->cache->get($key);
		if ($cached !== null) {
			return $cached;
		}

		$answer = $this->github->isVerified($owner);
		if ($answer === null) {
			return false;
		}
		$this->cache->put($key, $answer);

		return $answer;
	}

	private function isShipped(string $appId): bool {
		try {
			return $this->appManager->isShipped($appId);
		} catch (\Throwable) {
			return false;
		}
	}
}
