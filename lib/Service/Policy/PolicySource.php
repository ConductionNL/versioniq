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
use OCP\IConfig;

/**
 * Where automatic updates read their settings and policies from (design D4):
 * the stores, or, when `config.php` sets `versioniq.policy_file`, that file,
 * read afresh every time and never mixed with the stores. An invalid or
 * unreadable file is an error, not a fall-back to the database.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
class PolicySource {
	/** The `config.php` key naming the policy file. */
	public const SYSTEM_KEY = 'versioniq.policy_file';

	public function __construct(
		private IConfig $systemConfig,
		private PolicyStore $policyStore,
		private AutoUpdateSettingsStore $settingsStore,
	) {
	}

	/**
	 * The policy file `config.php` names, or null when the stores are the source.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public function filePath(): ?string {
		$path = $this->systemConfig->getSystemValueString(self::SYSTEM_KEY, '');
		return trim($path) === '' ? null : $path;
	}

	/**
	 * The settings and policies in force: the file's in file mode, else the stores'.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @throws PolicyFileException In file mode, when the file cannot be read or is invalid.
	 */
	public function current(): PolicyFileContents {
		$path = $this->filePath();
		if ($path === null) {
			return PolicyFileContents::fromStores($this->policyStore, $this->settingsStore);
		}

		return PolicyFile::read($path);
	}
}
