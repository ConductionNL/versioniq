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

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * A period of whole days in the admin's time zone, as UTC bounds of the
 * audit table's `created_at` (audit-security-reports D5).
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
final class AuditPeriod {
	/** The stored timestamp format, UTC. */
	public const FORMAT = 'Y-m-d H:i:s';

	/** The lower bound when no `from` date is given. */
	private const BEGINNING = '1970-01-01 00:00:00';

	/**
	 * @param string      $fromUtc Lower bound, inclusive.
	 * @param string|null $toUtc   Upper bound, inclusive, or null for none.
	 */
	public function __construct(
		public readonly string $fromUtc,
		public readonly ?string $toUtc,
	) {
	}

	/**
	 * The period from the `from` day's start to the `to` day's end in a time zone.
	 *
	 * @param string $from     `Y-m-d`, or '' for no lower bound.
	 * @param string $to       `Y-m-d`, or '' for no upper bound.
	 * @param string $timeZone An IANA time zone, or '' for UTC.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When a date or the time zone cannot be read, or `from` is after `to`.
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
	 */
	public static function fromDays(string $from, string $to, string $timeZone): self {
		$zone = self::zone(timeZone: $timeZone);
		$utc  = new DateTimeZone('UTC');

		$fromUtc = self::BEGINNING;
		if ($from !== '') {
			$fromUtc = self::day(value: $from, zone: $zone, time: '00:00:00')->setTimezone($utc)->format(self::FORMAT);
		}

		$toUtc = null;
		if ($to !== '') {
			$toUtc = self::day(value: $to, zone: $zone, time: '23:59:59')->setTimezone($utc)->format(self::FORMAT);
		}

		if ($toUtc !== null && $fromUtc > $toUtc) {
			throw new InvalidArgumentException('The period starts after it ends.');
		}

		return new self(fromUtc: $fromUtc, toUtc: $toUtc);
	}

	/**
	 * Whether a stored timestamp lies in the period.
	 *
	 * @param string $createdAt `Y-m-d H:i:s` UTC.
	 *
	 * @return bool
	 */
	public function contains(string $createdAt): bool {
		return $createdAt >= $this->fromUtc && ($this->toUtc === null || $createdAt <= $this->toUtc);
	}

	/**
	 * Read a time zone, UTC when empty.
	 *
	 * @param string $timeZone The IANA name.
	 *
	 * @return DateTimeZone
	 *
	 * @throws InvalidArgumentException When it is not a time zone.
	 */
	private static function zone(string $timeZone): DateTimeZone {
		if ($timeZone === '') {
			return new DateTimeZone('UTC');
		}

		try {
			return new DateTimeZone($timeZone);
		} catch (Throwable $e) {
			throw new InvalidArgumentException('Unknown time zone.');
		}
	}

	/**
	 * Read a `Y-m-d` day at a time of day.
	 *
	 * @param string       $value The day.
	 * @param DateTimeZone $zone  Its time zone.
	 * @param string       $time  `H:i:s`.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When it is not a valid day.
	 */
	private static function day(string $value, DateTimeZone $zone, string $time): DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value . ' ' . $time, $zone);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new InvalidArgumentException('Dates are YYYY-MM-DD.');
		}

		return $parsed;
	}
}
