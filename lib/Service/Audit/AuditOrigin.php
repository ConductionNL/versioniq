<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

/**
 * Where the operation an audit row records came from: the admin page (`web`),
 * `occ` (`cli`), the nightly automatic update (`job`) or drift detection
 * (`listener`).
 *
 * The job and the listener run their work inside {@see during()}, so every row
 * written along the way, by any installer, carries their origin without each
 * method passing it on. Outside that, a command-line process is `cli` and
 * anything else `web`.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md
 */
final class AuditOrigin {
	public const WEB = 'web';
	public const CLI = 'cli';
	public const JOB = 'job';
	public const LISTENER = 'listener';

	/** @var list<string> The origins set by enclosing {@see during()} calls, innermost last. */
	private static array $stack = [];

	/** @var string|null The SAPI to read, for tests; null reads PHP_SAPI. */
	private static ?string $sapi = null;

	/**
	 * Run work with an origin, and restore the previous one after, also when the work throws.
	 *
	 * @template T
	 * @param string $origin One of the constants.
	 * @param callable():T $work The work.
	 * @return T What the work returned.
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md
	 */
	public static function during(string $origin, callable $work): mixed {
		self::$stack[] = $origin;
		try {
			return $work();
		} finally {
			array_pop(self::$stack);
		}
	}

	/**
	 * The origin of what is running now.
	 *
	 * @return string One of the constants.
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md
	 */
	public static function current(): string {
		if (self::$stack !== []) {
			return self::$stack[count(self::$stack) - 1];
		}

		return (self::$sapi ?? PHP_SAPI) === 'cli' ? self::CLI : self::WEB;
	}

	/**
	 * Pretend a SAPI, for tests; null reads the real one again.
	 *
	 * @param string|null $sapi The SAPI.
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md
	 */
	public static function useSapi(?string $sapi): void {
		self::$sapi = $sapi;
	}
}
