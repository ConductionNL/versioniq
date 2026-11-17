<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * The public surface of ForgeRegistry that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForgeRegistryInterface {
	public const GITLAB_WEB_DEFAULT = 'https://gitlab.com';

	/**
	 * The configured self-hosted Forgejo or Gitea host (its web base URL), or
	 * an empty string when none is set.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function forgejoHost(): string;

	/**
	 * Sets or clears the self-hosted Forgejo or Gitea host. An empty value
	 * clears it. A host must be an https URL with no credentials, query or
	 * fragment: tokens are sent to it. A stale `api_base` override is dropped
	 * so the API base follows the new host.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @throws InvalidArgumentException when the host is not acceptable
	 */
	public function setForgejoHost(string $host): void;

	/**
	 * The GitLab host releases come from: gitlab.com, or the self-managed
	 * host set on the Sources tab.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function gitlabHost(): string;

	/**
	 * Point GitLab at a self-managed host, or back at gitlab.com with an
	 * empty value. https only, no credentials, query or fragment; a stale
	 * `api_base` override is dropped (design D2).
	 *
	 * @throws InvalidArgumentException when the host is not a bare https address
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
	 */
	public function setGitlabHost(string $host): void;
}
