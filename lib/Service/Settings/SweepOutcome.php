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
 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
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
	 * @spec openspec/specs/security-advisory-correlation/spec.md#requirement-the-check-line-says-whether-the-last-sweep-succeeded
	 */
	public static function of(array $map): array {
		$errors = self::unchecked($map);

		return ['status' => self::statusOf(count($errors), count($map)), 'errors' => $errors];
	}

	/**
	 * The ids of the entries that carry an error.
	 *
	 * @param array<array-key, mixed> $map
	 * @return list<string>
	 */
	private static function unchecked(array $map): array {
		$errors = [];
		foreach ($map as $appId => $entry) {
			if (is_array($entry) && ($entry['error'] ?? null) !== null) {
				$errors[] = (string)$appId;
			}
		}

		return $errors;
	}

	/**
	 * `ok` without errors, `failed` when every entry has one, else `partial`.
	 */
	private static function statusOf(int $errors, int $entries): string {
		if ($errors === 0) {
			return self::OK;
		}

		return $errors === $entries ? self::FAILED : self::PARTIAL;
	}
}
