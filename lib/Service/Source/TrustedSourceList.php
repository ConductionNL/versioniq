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

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and enforces the trusted-source allowlist. Bindings whose owner/repo
 * does not match any configured glob are rejected before any HTTP fetch or
 * filesystem write happens.
 *
 * @psalm-api
 */
class TrustedSourceList {
	private const CONFIG_KEY = 'trusted_sources';

	/**
	 * Forge-qualified default allowlist. Note the owner differs per forge:
	 * `ConductionNL` on GitHub, `Conduction` on Codeberg.
	 *
	 * @var list<string>
	 */
	private const DEFAULT_PATTERNS = ['github:ConductionNL/*', 'codeberg:Conduction/*'];

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * Reads the allowlist globs, defaulting to `ConductionNL/*` when unset; see "Trusted-source allowlist".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return list<string>
	 */
	public function getPatterns(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return self::DEFAULT_PATTERNS;
		}

		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return self::DEFAULT_PATTERNS;
		}

		if (!is_array($decoded)) {
			return self::DEFAULT_PATTERNS;
		}

		$patterns = [];
		/** @var mixed $entry */
		foreach ($decoded as $entry) {
			if (is_string($entry) && trim($entry) !== '') {
				$patterns[] = trim($entry);
			}
		}

		return $patterns === [] ? self::DEFAULT_PATTERNS : $patterns;
	}

	/**
	 * Persists a cleaned set of allowlist globs; see "Trusted-source allowlist".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @param list<string> $patterns
	 */
	public function setPatterns(array $patterns): void {
		$cleaned = [];
		foreach ($patterns as $entry) {
			if (trim($entry) !== '') {
				$cleaned[] = trim($entry);
			}
		}

		$this->config->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode($cleaned, JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * Returns whether a source id matches the allowlist (glob-matched); see "Trusted-source allowlist".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function isAllowed(string $sourceId): bool {
		if ($sourceId === 'appstore') {
			return true;
		}

		// A forge-release source id is `{forge}:owner/repo`. Patterns are
		// forge-qualified globs; a legacy bare `owner/repo` pattern is
		// normalized to `github:owner/repo` (github was the only forge before).
		if (!$this->isForgeQualified($sourceId)) {
			return false;
		}

		foreach ($this->getPatterns() as $pattern) {
			if (fnmatch($this->normalizePattern($pattern), $sourceId, FNM_NOESCAPE)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Throws when a source id is not allowlisted, before any fetch/write; see "Trusted-source allowlist".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @throws UntrustedSourceException
	 */
	public function assertAllowed(string $sourceId): void {
		if (!$this->isAllowed($sourceId)) {
			throw new UntrustedSourceException(
				$sourceId,
				sprintf(
					'allowlist patterns: %s',
					implode(', ', $this->getPatterns())
				)
			);
		}
	}

	/**
	 * Asserts a binding's source id is allowlisted; see "Trusted-source allowlist".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function assertBindingAllowed(SourceBinding $binding): void {
		$this->assertAllowed($binding->getId());
	}

	/**
	 * Whether a source id is a well-formed forge-qualified id
	 * (`{forge}:owner/repo` for a known forge).
	 */
	private function isForgeQualified(string $sourceId): bool {
		foreach (SourceBinding::FORGES as $forge) {
			$prefix = $forge . ':';
			if (!str_starts_with($sourceId, $prefix)) {
				continue;
			}
			$ownerRepo = substr($sourceId, strlen($prefix));

			return str_contains($ownerRepo, '/')
				&& !str_starts_with($ownerRepo, '/')
				&& !str_ends_with($ownerRepo, '/');
		}

		return false;
	}

	/**
	 * Normalizes an allowlist pattern to a forge-qualified glob. A pattern that
	 * already carries a known forge prefix is used as-is; a legacy bare
	 * `owner/repo` pattern is treated as `github:owner/repo` (github was the
	 * only forge before this capability existed).
	 */
	private function normalizePattern(string $pattern): string {
		foreach (SourceBinding::FORGES as $forge) {
			if (str_starts_with($pattern, $forge . ':')) {
				return $pattern;
			}
		}

		return SourceBinding::FORGE_GITHUB . ':' . $pattern;
	}
}
