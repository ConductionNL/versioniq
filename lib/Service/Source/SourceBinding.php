<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use InvalidArgumentException;

/**
 * Immutable representation of which source an installed app is bound to.
 *
 * Persisted as a JSON blob under app config key `source.{appId}`.
 */
final class SourceBinding {
	public const KIND_APPSTORE = 'appstore';
	public const KIND_GITHUB_RELEASE = 'github-release';

	/**
	 * Forges a `github-release` binding may target. The `kind` stays
	 * `github-release` for backward compatibility (legacy rows have no forge);
	 * the `forge` config field discriminates GitHub from a Forgejo or Gitea
	 * host. `codeberg` is retired (issue #437) but stays readable, so a
	 * binding stored under it keeps working; see {@see ForgeRegistry}.
	 */
	public const FORGE_GITHUB = 'github';
	public const FORGE_FORGEJO = 'forgejo';
	public const FORGE_CODEBERG = 'codeberg';

	/**
	 * Every forge a stored binding, source id or trusted pattern may carry,
	 * the retired one included.
	 *
	 * @var list<string>
	 */
	public const FORGES = [self::FORGE_GITHUB, self::FORGE_FORGEJO, self::FORGE_CODEBERG];
	private const ALLOWED_FORGES = self::FORGES;

	/**
	 * Cap on the number of `version => sha256` entries carried in the binding's
	 * `sha256` map, oldest evicted first; see "Recorded digests are
	 * binding-scoped and surfaced".
	 */
	public const MAX_RECORDED_SHA = 200;

	private const SHA256_PATTERN = '/^[a-f0-9]{64}$/';

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $config = [],
		public readonly ?string $boundAt = null,
	) {
		if ($kind !== self::KIND_APPSTORE && $kind !== self::KIND_GITHUB_RELEASE) {
			throw new InvalidArgumentException('Unknown source kind: ' . $kind);
		}

		if ($kind === self::KIND_GITHUB_RELEASE) {
			if (!isset($config['owner']) || !is_string($config['owner']) || $config['owner'] === '') {
				throw new InvalidArgumentException('github-release binding requires non-empty owner');
			}
			if (!isset($config['repo']) || !is_string($config['repo']) || $config['repo'] === '') {
				throw new InvalidArgumentException('github-release binding requires non-empty repo');
			}
			// Reject path-traversal characters in owner/repo. fnmatch in
			// TrustedSourceList lets `*` match `/`, so `ConductionNL/../../../x`
			// would otherwise pass the allowlist. GitHub's own owner/repo
			// charset is the same as below. CWE-22 / OWASP A01:2021.
			if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $config['owner'])) {
				throw new InvalidArgumentException('github-release owner contains invalid characters');
			}
			if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $config['repo'])) {
				throw new InvalidArgumentException('github-release repo contains invalid characters');
			}
			if (isset($config['forge']) && (!is_string($config['forge']) || !in_array($config['forge'], self::ALLOWED_FORGES, true))) {
				throw new InvalidArgumentException('github-release binding has an unknown forge');
			}
		}
	}

	/**
	 * Returns the forge a `github-release` binding targets (`github`|`forgejo`|`codeberg`),
	 * defaulting to `github` when absent (legacy rows). Empty string for
	 * non-release bindings.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getForge(): string {
		if ($this->kind !== self::KIND_GITHUB_RELEASE) {
			return '';
		}
		/** @var mixed $forge */
		$forge = $this->config['forge'] ?? null;

		return is_string($forge) && $forge !== '' ? $forge : self::FORGE_GITHUB;
	}

	/**
	 * Returns the canonical source id (`appstore` or `github:owner/repo`); see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getId(): string {
		if ($this->kind === self::KIND_APPSTORE) {
			return 'appstore';
		}

		// Forge-qualified id. For github this is byte-identical to the legacy
		// `github:owner/repo` form (getForge() defaults to github).
		return $this->getForge() . ':' . $this->configString('owner') . '/' . $this->configString('repo');
	}

	/**
	 * Returns the `owner/repo` for github bindings, null otherwise; see "GitHub releases as a source".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getOwnerRepo(): ?string {
		if ($this->kind !== self::KIND_GITHUB_RELEASE) {
			return null;
		}

		return $this->configString('owner') . '/' . $this->configString('repo');
	}

	public function getAssetPattern(): string {
		/** @var mixed $pattern */
		$pattern = $this->config['assetPattern'] ?? '*.tar.gz';

		return is_string($pattern) && $pattern !== '' ? $pattern : '*.tar.gz';
	}

	/**
	 * Returns the SHA-256 recorded for `$version` (from a prior successful
	 * external install), or null when none is recorded or the stored value is
	 * not a valid 64-character lowercase hex digest; see "SHA-256 recorded on
	 * first successful external install".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getRecordedSha(string $version): ?string {
		return $this->sanitizedShaMap()[$version] ?? null;
	}

	/**
	 * Returns the full recorded-digest map (`version => hex digest`),
	 * sanitized: invalid entries are dropped; see "Recorded digests are
	 * binding-scoped and surfaced".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return array<string, string>
	 */
	public function getRecordedShaMap(): array {
		return $this->sanitizedShaMap();
	}

	/**
	 * Returns an immutable copy with the recorded digest for `$version` set to
	 * `$sha`. The map is capped at `MAX_RECORDED_SHA` entries, evicting the
	 * oldest (by insertion order) first; see "SHA-256 recorded on first
	 * successful external install".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @throws InvalidArgumentException when `$sha` is not a 64-character lowercase hex digest
	 */
	public function withRecordedSha(string $version, string $sha): self {
		$sha = strtolower($sha);
		if (preg_match(self::SHA256_PATTERN, $sha) !== 1) {
			throw new InvalidArgumentException('Recorded SHA-256 must be a 64-character hex digest.');
		}
		if ($version === '') {
			throw new InvalidArgumentException('Cannot record a SHA-256 for an empty version.');
		}

		$map = $this->sanitizedShaMap();
		// Re-insert at the end so this version counts as the most recent entry
		// for oldest-first eviction.
		unset($map[$version]);
		$map[$version] = $sha;
		while (count($map) > self::MAX_RECORDED_SHA) {
			array_shift($map);
		}

		$config = $this->config;
		$config['sha256'] = $map;

		return new self($this->kind, $config, $this->boundAt);
	}

	/**
	 * Returns an immutable copy carrying the given recorded-digest map, replacing
	 * any current one. Used to preserve a stored binding's digests when the same
	 * source is resolved through an explicit one-off override — the override
	 * builds a fresh binding, and without carrying the digests over it would
	 * silently bypass trust-on-first-use enforcement. Invalid entries are
	 * dropped and the cap is applied.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @param array<string, string> $map
	 */
	public function withRecordedShaMap(array $map): self {
		$binding = $this;
		foreach ($map as $version => $sha) {
			if (is_string($version) && $version !== '' && is_string($sha)
				&& preg_match(self::SHA256_PATTERN, strtolower($sha)) === 1) {
				$binding = $binding->withRecordedSha($version, $sha);
			}
		}

		return $binding;
	}

	/**
	 * @return array<string, string>
	 */
	private function sanitizedShaMap(): array {
		/** @var mixed $map */
		$map = $this->config['sha256'] ?? [];
		if (!is_array($map)) {
			return [];
		}

		$sanitized = [];
		/** @var mixed $sha */
		foreach ($map as $version => $sha) {
			if (!is_string($version) || $version === '' || !is_string($sha)) {
				continue;
			}
			$lower = strtolower($sha);
			if (preg_match(self::SHA256_PATTERN, $lower) !== 1) {
				continue;
			}
			$sanitized[$version] = $lower;
		}

		return $sanitized;
	}

	/**
	 * Returns a config value as a string, or '' when absent/non-string.
	 */
	private function configString(string $key): string {
		/** @var mixed $value */
		$value = $this->config[$key] ?? null;

		return is_string($value) ? $value : '';
	}

	/**
	 * Serializes the binding to its persisted JSON shape; see "Source binding"
	 * and "Recorded digests are binding-scoped and surfaced".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$payload = array_merge(['kind' => $this->kind], $this->config);

		$shaMap = $this->sanitizedShaMap();
		if ($shaMap !== []) {
			$payload['sha256'] = $shaMap;
		} else {
			unset($payload['sha256']);
		}

		if ($this->boundAt !== null) {
			$payload['boundAt'] = $this->boundAt;
		}

		return $payload;
	}

	/**
	 * Reconstructs a binding from its persisted payload; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$kind = $payload['kind'] ?? null;
		if (!is_string($kind)) {
			throw new InvalidArgumentException('Source binding payload missing "kind"');
		}

		$config = array_filter(
			$payload,
			static fn (int|string $key): bool => is_string($key) && $key !== 'kind' && $key !== 'boundAt',
			ARRAY_FILTER_USE_KEY,
		);
		/** @var array<string, mixed> $config */

		$boundAt = isset($payload['boundAt']) && is_string($payload['boundAt']) ? $payload['boundAt'] : null;

		return new self($kind, $config, $boundAt);
	}

	/**
	 * Builds an App Store binding; see "Source abstraction".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public static function appStore(): self {
		return new self(self::KIND_APPSTORE);
	}

	/**
	 * Builds a validated github-release binding with a boundAt timestamp; see "Source binding".
	 *
	 * Public API kept for callers and tests; the controller and source-id
	 * parser build through {@see release()} since issue #437.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @psalm-api
	 */
	public static function github(string $owner, string $repo, string $assetPattern = '*.tar.gz'): self {
		return self::forgeRelease(self::FORGE_GITHUB, $owner, $repo, $assetPattern);
	}

	/**
	 * Builds a validated codeberg (Forgejo) release binding; mirrors ::github().
	 * Codeberg is retired as a forge of its own (issue #437); this stays for
	 * stored data and tests.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @psalm-api
	 */
	public static function codeberg(string $owner, string $repo, string $assetPattern = '*.tar.gz'): self {
		return self::forgeRelease(self::FORGE_CODEBERG, $owner, $repo, $assetPattern);
	}

	/**
	 * Builds a validated release binding on any known forge; an unknown forge
	 * is rejected by the constructor.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @throws InvalidArgumentException
	 */
	public static function release(string $forge, string $owner, string $repo, string $assetPattern = '*.tar.gz'): self {
		return self::forgeRelease($forge, $owner, $repo, $assetPattern);
	}

	/**
	 * Shared factory for forge-release bindings with a boundAt timestamp.
	 */
	private static function forgeRelease(string $forge, string $owner, string $repo, string $assetPattern): self {
		return new self(
			self::KIND_GITHUB_RELEASE,
			[
				'forge' => $forge,
				'owner' => $owner,
				'repo' => $repo,
				'assetPattern' => $assetPattern,
			],
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
		);
	}
}
