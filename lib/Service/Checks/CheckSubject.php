<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Checks;

/**
 * What a check is asked about: the app, the version it would get, the version
 * it has now ('' when none) and the folder holding the unpacked package.
 *
 * @psalm-api
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class CheckSubject {
	public function __construct(
		public readonly string $appId,
		public readonly string $fromVersion,
		public readonly string $version,
		public readonly string $path,
	) {
	}

	/**
	 * The placeholder values, each passed through `$encode`.
	 *
	 * @param callable(string): string $encode
	 * @return array<string, string>
	 */
	public function placeholders(callable $encode, bool $withPath): array {
		$values = [
			'{appId}' => $encode($this->appId),
			'{version}' => $encode($this->version),
			'{fromVersion}' => $encode($this->fromVersion),
		];
		if ($withPath) {
			$values['{path}'] = $encode($this->path);
		}

		return $values;
	}
}
