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

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
	public const CACHE_PREFIX = 'publisher.github.';

	/** How long a GitHub organisation answer is reused: one week. */
	public const CACHE_TTL_SECONDS = 604800;

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
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
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
		$marks = [];
		if ($this->isShipped($appId)) {
			$marks[] = self::MARK_SHIPPED;
		}
		if ($featured) {
			$marks[] = self::MARK_FEATURED;
		}

		$forgeSource = self::forgeSourceId($binding);
		if ($forgeSource !== null && $forgeSource['forge'] === SourceBinding::FORGE_GITHUB
			&& $this->isVerifiedOrganisation($forgeSource['owner'])
		) {
			$marks[] = self::MARK_VERIFIED_ORG;
		}
		if ($forgeSource !== null && $this->trustedSources->isAllowed($forgeSource['id'])) {
			$marks[] = self::MARK_TRUSTED;
		}

		return $marks;
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
		if (array_key_exists($key, $this->verified)) {
			return $this->verified[$key];
		}

		$now = $this->timeFactory->getTime();
		$cached = $this->cachedAnswer($key, $now);
		if ($cached !== null) {
			return $this->verified[$key] = $cached;
		}

		$answer = $this->askGithub($owner);
		if ($answer === null) {
			return $this->verified[$key] = false;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CACHE_PREFIX . $key,
			json_encode(['verified' => $answer, 'at' => $now], JSON_THROW_ON_ERROR)
		);

		return $this->verified[$key] = $answer;
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
		if (!is_array($authors)) {
			return is_string($authors) && trim($authors) !== '' ? [['name' => trim($authors)]] : [];
		}
		if (isset($authors['name']) || isset($authors['@value'])) {
			$authors = [$authors];
		}

		$names = [];
		/** @var mixed $author */
		foreach ($authors as $author) {
			/** @var mixed $name */
			$name = is_array($author) ? ($author['name'] ?? $author['@value'] ?? null) : $author;
			if (is_string($name) && trim($name) !== '') {
				$names[] = ['name' => trim($name)];
			}
		}

		return $names;
	}

	/**
	 * The forge, owner and source id (`<forge>:<owner>/<repo>`) of a forge
	 * release binding, or null for any other source.
	 *
	 * @param array<string, mixed> $binding
	 * @return array{forge: string, owner: string, id: string}|null
	 */
	private static function forgeSourceId(array $binding): ?array {
		if (($binding['kind'] ?? null) !== SourceBinding::KIND_GITHUB_RELEASE) {
			return null;
		}
		$owner = $binding['owner'] ?? null;
		$repo = $binding['repo'] ?? null;
		if (!is_string($owner) || $owner === '' || !is_string($repo) || $repo === '') {
			return null;
		}
		/** @var mixed $forge */
		$forge = $binding['forge'] ?? null;
		$forge = is_string($forge) && $forge !== '' ? $forge : SourceBinding::FORGE_GITHUB;

		return ['forge' => $forge, 'owner' => $owner, 'id' => $forge . ':' . $owner . '/' . $repo];
	}

	private function isShipped(string $appId): bool {
		try {
			return $this->appManager->isShipped($appId);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The cached answer when it is younger than a week, otherwise null.
	 */
	private function cachedAnswer(string $key, int $now): ?bool {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CACHE_PREFIX . $key, '');
		if ($raw === '') {
			return null;
		}

		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($decoded)) {
			return null;
		}
		/** @var mixed $verified */
		$verified = $decoded['verified'] ?? null;
		/** @var mixed $at */
		$at = $decoded['at'] ?? null;
		if (!is_bool($verified) || !is_int($at)) {
			return null;
		}

		return ($now - $at) < self::CACHE_TTL_SECONDS ? $verified : null;
	}

	/**
	 * GitHub's answer: true or false for an organisation, false for a personal
	 * account (404), null when GitHub could not be asked.
	 */
	private function askGithub(string $owner): ?bool {
		try {
			$response = $this->clientService->newClient()->get(
				'https://api.github.com/orgs/' . rawurlencode($owner),
				[
					'headers' => ['Accept' => 'application/vnd.github+json'],
					'timeout' => 10,
					'http_errors' => false,
				]
			);
		} catch (\Exception $error) {
			$this->logger->info('PublisherTrust: could not ask GitHub about an owner', ['errorMessage' => $error->getMessage()]);

			return null;
		}

		$status = $response->getStatusCode();
		if ($status === 404) {
			return false;
		}
		if ($status !== 200) {
			return null;
		}

		try {
			/** @var mixed $body */
			$body = json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return is_array($body) && ($body['is_verified'] ?? false) === true;
	}
}
