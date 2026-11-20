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
 * A policy file that cannot be used, with every problem found, each one
 * starting with its JSON path.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
final class PolicyFileException extends \RuntimeException {
	/**
	 * @param list<string> $errors The problems, each as `<json path>: <reason>`.
	 */
	public function __construct(
		public readonly array $errors,
	) {
		parent::__construct(implode('; ', $errors));
	}
}
