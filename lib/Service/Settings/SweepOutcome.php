<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Settings;

/**
 * Whether a background sweep read every source: `ok` when no app carries an
 * error, `failed` when every app does (or the sweep itself failed), `partial`
 * otherwise, with the ids of the apps that could not be checked.
 *
 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
 */
final class SweepOutcome {
	public const OK = 'ok';
	public const PARTIAL = 'partial';
	public const FAILED = 'failed';

	/**
	 * The outcome of a sweep's per-app map (entries with an `error` key).
	 *
	 * @param array<array-key, mixed> $map
	 * @return array{status: string, errors: list<string>}
	 * @spec openspec/changes/inventory-pins-checks-and-pending/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public static function of(array $map): array {
		$errors = [];
		foreach ($map as $appId => $entry) {
			if (is_array($entry) && ($entry['error'] ?? null) !== null) {
				$errors[] = (string)$appId;
			}
		}

		$status = self::PARTIAL;
		if ($errors === []) {
			$status = self::OK;
		} elseif (count($errors) === count($map)) {
			$status = self::FAILED;
		}

		return ['status' => $status, 'errors' => $errors];
	}
}
