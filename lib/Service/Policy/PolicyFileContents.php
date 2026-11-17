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

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;

/**
 * What a policy file holds, and what the stores hold in the same shape: the
 * kill switch, the window, the default level and the per-app levels. A null
 * setting is one the file does not name.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
final class PolicyFileContents {
	/**
	 * @param array<string, string> $policies Level per app id.
	 */
	public function __construct(
		public readonly ?bool $enabled,
		public readonly ?string $window,
		public readonly ?string $defaultLevel,
		public readonly array $policies,
	) {
	}

	/**
	 * The stored settings and policies, as a file would hold them.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public static function fromStores(PolicyStore $policyStore, AutoUpdateSettingsStore $settingsStore): self {
		$policies = PolicyStore::levelsOf($policyStore->all());
		ksort($policies);

		return new self(
			$settingsStore->isEnabled(),
			$settingsStore->getWindow(),
			$settingsStore->getDefaultLevel(),
			$policies,
		);
	}
}
