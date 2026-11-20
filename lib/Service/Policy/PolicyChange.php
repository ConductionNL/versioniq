<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

/**
 * One difference between the stores and a policy file: a setting
 * (`autoUpdate.enabled`, `autoUpdate.window`, `autoUpdate.defaultLevel`) or an
 * app's policy (`policies.<appId>`), from its stored value to the file's. A
 * null side is "not set": a policy the file adds, or one `--prune` removes.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
final class PolicyChange {
	public function __construct(
		public readonly string $key,
		public readonly ?string $from,
		public readonly ?string $to,
	) {
	}

	/**
	 * The app id when this change is a per-app policy, else null.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function appId(): ?string {
		return str_starts_with($this->key, 'policies.') ? substr($this->key, strlen('policies.')) : null;
	}

	/**
	 * One line for the import's output, e.g. `policies.openregister: patch -> minor`.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function describe(): string {
		return $this->key . ': ' . ($this->from ?? '(not set)') . ' -> ' . ($this->to ?? '(removed)');
	}
}
