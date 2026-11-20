<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Command;

use OCA\Versioniq\Service\Checks\CheckRunner;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCP\AppFramework\Http;

/**
 * The `versioniq:install` exit code of an install outcome, shared by
 * `versioniq:install` and `versioniq:rollback` so a failed rollback step exits
 * with the code the same install would have.
 *
 * 0 success/dry-run-ok, 1 unknown/unclassified, 2 unknown app / bad arguments,
 * 3 downgrade refused, 4 preflight_permission, 5 download, 6 integrity
 * (checksum_mismatch|appid_mismatch|version_mismatch|sha_mismatch),
 * 7 incompatible, 8 finalize, 9 untrusted source.
 *
 * @spec openspec/specs/cli-commands/spec.md
 * @psalm-api
 */
final class InstallExitCode {
	public const OK = 0;
	public const UNKNOWN = 1;
	public const BAD_ARGUMENTS = 2;
	public const DOWNGRADE_REFUSED = 3;
	public const PREFLIGHT_PERMISSION = 4;
	public const DOWNLOAD = 5;
	public const INTEGRITY = 6;
	public const INCOMPATIBLE = 7;
	public const FINALIZE = 8;
	public const UNTRUSTED_SOURCE = 9;
	/** An update check failed in a dry run (see "Admins and operators attach checks that every dry run runs"). */
	public const CHECKS_FAILED = 12;

	/**
	 * Maps the structured outcome from `InstallerService::installAppVersion()`
	 * to the documented exit code. A 403 without a known category is the
	 * trusted-source allowlist guard.
	 *
	 * @param array<array-key, mixed> $payload
	 * @spec openspec/specs/cli-commands/spec.md
	 */
	public static function for(int $statusCode, array $payload): int {
		if ($statusCode === Http::STATUS_OK) {
			return CheckRunner::firstFailure(is_array($payload['checks'] ?? null) ? $payload['checks'] : []) === null
				? self::OK
				: self::CHECKS_FAILED;
		}

		/** @var mixed $category */
		$category = $payload['category'] ?? null;
		$mapped = is_string($category) ? self::forCategory($category) : null;

		return $mapped ?? self::forStatus($statusCode);
	}

	/**
	 * The documented exit code of a failure category, or null for a category
	 * without one of its own.
	 */
	private static function forCategory(string $category): ?int {
		return match ($category) {
			FailureClassifier::CATEGORY_DOWNGRADE_GUARD => self::DOWNGRADE_REFUSED,
			FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION => self::PREFLIGHT_PERMISSION,
			FailureClassifier::CATEGORY_DOWNLOAD => self::DOWNLOAD,
			FailureClassifier::CATEGORY_CHECKSUM_MISMATCH,
			FailureClassifier::CATEGORY_SHA_MISMATCH,
			FailureClassifier::CATEGORY_APPID_MISMATCH,
			FailureClassifier::CATEGORY_VERSION_MISMATCH => self::INTEGRITY,
			FailureClassifier::CATEGORY_INCOMPATIBLE => self::INCOMPATIBLE,
			FailureClassifier::CATEGORY_FINALIZE => self::FINALIZE,
			FailureClassifier::CATEGORY_CHECKS_FAILED => self::CHECKS_FAILED,
			default => null,
		};
	}

	/**
	 * The exit code an HTTP status maps to when its category named none.
	 */
	private static function forStatus(int $statusCode): int {
		if ($statusCode === Http::STATUS_FORBIDDEN) {
			return self::UNTRUSTED_SOURCE;
		}

		if ($statusCode === Http::STATUS_BAD_REQUEST || $statusCode === Http::STATUS_NOT_FOUND) {
			return self::BAD_ARGUMENTS;
		}

		return self::UNKNOWN;
	}
}
