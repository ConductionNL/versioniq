<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use InvalidArgumentException;

/**
 * Thrown when a new binding or trusted pattern names a retired forge
 * (`codeberg`). What was stored under the forge before keeps working; only
 * new entries are refused. The HTTP layer maps this to 400.
 *
 * @spec openspec/changes/codeberg-is-no-release-source/specs/external-sources/spec.md
 *
 * @psalm-api
 */
final class RetiredForgeException extends InvalidArgumentException {
	public function __construct(
		public readonly string $forge,
	) {
		parent::__construct(sprintf(
			'Forge "%s" is no longer offered for a new binding or trusted pattern. Use github, or a self-hosted forgejo host.',
			$forge
		));
	}
}
