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

/**
 * The public surface of AutoUpdateNotifier that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AutoUpdateNotifierInterface {
	/**
	 * Notifies admins that an auto-update installed successfully; see "Every
	 * auto-update outcome is reported" ("Success notification").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function notifySuccess(string $appId, ?string $fromVersion, string $toVersion): void;

	/**
	 * Notifies admins that an auto-update attempt failed, carrying the
	 * classified category/hint; see "Every auto-update outcome is reported"
	 * ("Failure notification carries the classification").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function notifyFailure(string $appId, string $targetVersion, string $category, string $hint): void;
}
