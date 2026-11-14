<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Settings\AppConfigJsonRecords;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes per-app auto-update policies stored under app config key
 * `policy.{appId}`. Policies are JSON; a missing or malformed value is
 * treated as "no policy" (level `none`), logged, never fatal — mirrors
 * {@see \OCA\Versioniq\Service\Pin\PinStore} and
 * {@see \OCA\Versioniq\Service\Source\SourceBindingStore}.
 *
 * @psalm-api
 */
class PolicyStore {
	/** @use AppConfigJsonRecords<Policy> */
	use AppConfigJsonRecords;

	private const KEY_PREFIX = 'policy.';
	private const JSON_DEPTH = 8;
	private const MALFORMED_LOG = 'PolicyStore: malformed policy JSON, treating as none';
	private const INVALID_LOG = 'PolicyStore: invalid policy payload, treating as none';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
		// Optional and last so existing callers keep working; the container
		// always passes it. Without it the default is none.
		private ?AutoUpdateSettingsStore $settings = null,
	) {
	}

	/**
	 * Reads the persisted policy for an app (null if unset/invalid); see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function get(string $appId): ?Policy {
		return $this->readRecord($appId);
	}

	/**
	 * The effective policy level for an app — `none` when no policy is stored
	 * or the stored value is malformed; see "Per-app update policy" ("Absent
	 * policy means none").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function levelFor(string $appId): string {
		return $this->get($appId)?->level ?? Policy::LEVEL_NONE;
	}

	/**
	 * The level the job applies to an app: its own stored level, else the
	 * instance default; see "Instance default level".
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md
	 */
	public function effectiveLevelFor(string $appId): string {
		return $this->get($appId)?->level ?? $this->defaultLevel();
	}

	/**
	 * Whether an app's level is its own (`policy`) or the instance default (`default`).
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md
	 */
	public function levelSourceFor(string $appId): string {
		return $this->get($appId) !== null ? 'policy' : 'default';
	}

	/**
	 * The instance default level, `none` when no settings store is wired.
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md
	 */
	public function defaultLevel(): string {
		return $this->settings?->getDefaultLevel() ?? Policy::LEVEL_NONE;
	}

	/**
	 * Returns every persisted policy, keyed by app id.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @return array<string, Policy>
	 */
	public function all(): array {
		return $this->readAllRecords();
	}

	/**
	 * Persists a policy; see "Per-app update policy" ("Set a patch policy").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function set(string $appId, Policy $policy): void {
		$this->writeRecord($appId, $policy->toArray());
	}

	/**
	 * Removes an app's policy (a no-op when none exists); see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function clear(string $appId): void {
		$this->config->deleteKey(Application::APP_ID, $this->key($appId));
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function hydrate(array $payload): Policy {
		return Policy::fromArray($payload);
	}
}
