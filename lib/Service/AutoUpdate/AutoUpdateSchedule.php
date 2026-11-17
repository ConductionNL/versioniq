<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * On which weekdays each kind of update may install (autoupdate-schedules,
 * design D2, D3 and D5). Per kind: ISO-8601 weekdays (1 Monday to 7 Sunday) and
 * whether only the first such day of the month counts. A kind missing from the
 * stored value runs every day, weekly, which is the behaviour before schedules
 * existed; an empty day list means the kind never installs automatically.
 *
 * @psalm-api
 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
 */
final class AutoUpdateSchedule {
	private const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7];

	/** How far ahead nextRun() looks; a monthly day comes round within this. */
	private const HORIZON_DAYS = 62;

	/**
	 * @param array<string, array{days: list<int>, monthly: bool}> $kinds
	 */
	private function __construct(
		private array $kinds,
	) {
	}

	/**
	 * Every kind, every day, weekly.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public static function everyDay(): self {
		return self::fromArray([]);
	}

	/**
	 * A schedule from its stored shape; a kind it does not name runs every day.
	 * Call isValidArray() first for input that may be malformed.
	 *
	 * @param array<array-key, mixed> $value
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public static function fromArray(array $value): self {
		$kinds = [];
		foreach (UpdateType::ALL as $kind) {
			$entry = $value[$kind] ?? null;
			if (!is_array($entry)) {
				$kinds[$kind] = ['days' => self::EVERY_DAY, 'monthly' => false];
				continue;
			}

			$days = array_values(array_unique(array_map('intval', (array)($entry['days'] ?? []))));
			sort($days);
			$kinds[$kind] = ['days' => $days, 'monthly' => ($entry['monthly'] ?? false) === true];
		}

		return new self($kinds);
	}

	/**
	 * The stored shape.
	 *
	 * @return array<string, array{days: list<int>, monthly: bool}>
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public function toArray(): array {
		return $this->kinds;
	}

	/**
	 * Why a value is not a schedule, naming the field, or null when it is one:
	 * known kinds only, `days` a list of distinct integers 1 to 7, `monthly` a boolean.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public static function invalidReason(mixed $value): ?string {
		if (!is_array($value) || ($value !== [] && array_is_list($value))) {
			return 'schedule must be an object keyed by kind';
		}

		$reasons = array_filter(array_map(
			static fn (int|string $kind): ?string => self::invalidEntry((string)$kind, $value[$kind]),
			array_keys($value)
		));

		return $reasons === [] ? null : reset($reasons);
	}

	/**
	 * Whether a value is a valid schedule.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public static function isValidArray(mixed $value): bool {
		return self::invalidReason($value) === null;
	}

	private static function invalidEntry(string $kind, mixed $entry): ?string {
		if (!in_array($kind, UpdateType::ALL, true)) {
			return 'schedule.' . $kind . ' is not a kind of update; use patch, minor or major';
		}

		if (!self::hasDayList($entry)) {
			return 'schedule.' . $kind . '.days must be a list of weekdays';
		}

		return self::invalidDays($kind, $entry['days']) ?? self::invalidMonthly($kind, $entry);
	}

	private static function hasDayList(mixed $entry): bool {
		return is_array($entry) && array_key_exists('days', $entry) && is_array($entry['days']) && array_is_list($entry['days']);
	}

	/**
	 * @param list<mixed> $days
	 */
	private static function invalidDays(string $kind, array $days): ?string {
		if (array_filter($days, static fn (mixed $day): bool => !is_int($day) || $day < 1 || $day > 7) !== []) {
			return 'schedule.' . $kind . '.days holds weekdays 1 (Monday) to 7 (Sunday)';
		}

		if (count(array_unique($days)) !== count($days)) {
			return 'schedule.' . $kind . '.days names a weekday twice';
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function invalidMonthly(string $kind, array $entry): ?string {
		if (array_key_exists('monthly', $entry) && !is_bool($entry['monthly'])) {
			return 'schedule.' . $kind . '.monthly must be true or false';
		}

		return null;
	}

	/**
	 * The kinds that may install on the date the window opened.
	 *
	 * @return list<string>
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public function dueKinds(\DateTimeInterface $openedOn): array {
		return array_values(array_filter(UpdateType::ALL, fn (string $kind): bool => $this->isDue($kind, $openedOn)));
	}

	private function isDue(string $kind, \DateTimeInterface $date): bool {
		$entry = $this->kinds[$kind];
		if (!in_array((int)$date->format('N'), $entry['days'], true)) {
			return false;
		}

		return !$entry['monthly'] || (int)$date->format('j') <= 7;
	}

	/**
	 * The next opening of `$window` on which `$kind` may install, from `$now` on, or
	 * null when the kind has no days or the window is malformed. An opening still to
	 * come today counts; one already open counts as now.
	 *
	 * @spec openspec/changes/autoupdate-schedules/specs/auto-update-policies/spec.md
	 */
	public function nextRun(string $kind, string $window, \DateTimeImmutable $now): ?\DateTimeImmutable {
		if (($this->kinds[$kind]['days'] ?? []) === [] || !AutoUpdateWindow::isValid($window)) {
			return null;
		}

		// An opening already open counts as now.
		if (AutoUpdateWindow::isWithin($window, $now) && $this->isDue($kind, AutoUpdateWindow::openedOn($window, $now))) {
			return $now;
		}

		return $this->firstDueOpening($kind, self::upcomingOpenings($window, $now));
	}

	/**
	 * The window's openings that have not started yet, daily from the next one up to the horizon.
	 */
	private static function upcomingOpenings(string $window, \DateTimeImmutable $now): \DatePeriod {
		[$hour, $minute] = array_map('intval', explode(':', explode('-', trim($window))[0]));
		$todaysOpening = $now->setTime($hour, $minute);
		$first = $todaysOpening < $now ? $todaysOpening->add(new \DateInterval('P1D')) : $todaysOpening;
		$last = $todaysOpening->add(new \DateInterval('P' . self::HORIZON_DAYS . 'D'));

		return new \DatePeriod($first, new \DateInterval('P1D'), $last, \DatePeriod::INCLUDE_END_DATE);
	}

	/**
	 * The first of `$openings` on which `$kind` is due, or null.
	 */
	private function firstDueOpening(string $kind, \DatePeriod $openings): ?\DateTimeImmutable {
		foreach ($openings as $opening) {
			if ($opening instanceof \DateTimeImmutable && $this->isDue($kind, $opening)) {
				return $opening;
			}
		}

		return null;
	}
}
