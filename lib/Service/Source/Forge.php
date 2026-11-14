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

/**
 * Immutable description of a git forge (GitHub, Codeberg/Forgejo, …).
 *
 * The only behavioural deltas between forges are data: the API base URL, the
 * HTTP auth-header scheme (`Bearer` vs Forgejo's `token`), and whether the
 * forge exposes token scopes to the holder (GitHub does via `X-OAuth-Scopes`;
 * Forgejo does not). Everything else (release JSON shape) is identical, so the
 * driver and validator read these fields rather than branching per forge.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
final class Forge {
	public const SCHEME_BEARER = 'Bearer';
	public const SCHEME_TOKEN = 'token';

	/** GitHub's API shape, which Forgejo and Gitea share. */
	public const DIALECT_GITHUB = 'github';

	/** GitLab's API shape: projects by url-encoded path, token info, no repository advisories. */
	public const DIALECT_GITLAB = 'gitlab';

	public readonly string $id;
	public readonly string $apiBaseUrl;
	public readonly string $webBaseUrl;
	public readonly string $authScheme;
	public readonly bool $exposesScopeHeader;
	public readonly string $tokenCreateUrl;
	public readonly string $dialect;

	/**
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function __construct(string $id, ForgeUrls $urls, string $authScheme, bool $exposesScopeHeader, string $dialect = self::DIALECT_GITHUB) {
		$this->id = $id;
		$this->apiBaseUrl = $urls->api;
		$this->webBaseUrl = $urls->web;
		$this->authScheme = $authScheme;
		$this->exposesScopeHeader = $exposesScopeHeader;
		$this->tokenCreateUrl = $urls->tokenCreate;
		$this->dialect = $dialect;
	}

	/**
	 * Builds the `Authorization` header value for this forge's auth scheme,
	 * so callers never duplicate the Bearer-vs-token decision.
	 */
	public function authHeaderValue(string $token): string {
		return $this->authScheme . ' ' . $token;
	}

	/**
	 * Releases-listing endpoint for an `owner/repo` on this forge; on GitLab
	 * the project's full path, url-encoded as one segment.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function releasesEndpoint(string $ownerRepo): string {
		if ($this->dialect === self::DIALECT_GITLAB) {
			return sprintf('%s/projects/%s/releases?per_page=100', $this->apiBaseUrl, rawurlencode($ownerRepo));
		}

		return sprintf('%s/repos/%s/releases?per_page=100', $this->apiBaseUrl, $ownerRepo);
	}

	/**
	 * The endpoint that describes the token itself (its scopes and expiry),
	 * or null when the forge has none: GitLab's `personal_access_tokens/self`.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function tokenInfoEndpoint(): ?string {
		return $this->dialect === self::DIALECT_GITLAB ? $this->apiBaseUrl . '/personal_access_tokens/self' : null;
	}

	/**
	 * The authenticated-user probe endpoint, used to validate a token.
	 */
	public function userEndpoint(): string {
		return $this->apiBaseUrl . '/user';
	}

	/**
	 * Repository security-advisories endpoint for an `owner/repo`. GitHub and
	 * Forgejo both expose `/repos/{owner}/{repo}/security-advisories`; the JSON
	 * shape (ghsa_id / severity / summary / vulnerabilities[]) is compatible.
	 * GitLab has no repository advisories: null.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function advisoriesEndpoint(string $ownerRepo): ?string {
		if ($this->dialect === self::DIALECT_GITLAB) {
			return null;
		}

		return sprintf('%s/repos/%s/security-advisories?per_page=100', $this->apiBaseUrl, $ownerRepo);
	}
}
