<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Report;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\BackgroundJob\PruneAuditJob;
use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryExposureTracker;
use OCA\Versioniq\Service\Audit\AuditPeriod;
use OCA\Versioniq\Service\Audit\SecurityUpdateMatcher;
use OCP\IAppConfig;

/**
 * The remediation report (audit-security-reports D6): per severity how many
 * advisories opened and how they were resolved, the share resolved within
 * the deadline, what is open past it, the median days to fix, and a monthly
 * trend, all read from the audit history.
 *
 * Pairs opened with `tracking started` count in the opened and resolved
 * totals but not in the time figures, because their real start is unknown.
 * Without a deadline for a severity the deadline figures are null.
 *
 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
 */
class SecurityReportService {
	/** The severities the report lists, in order. */
	public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

	/** How a resolution by dismissal is recorded (advisories-triage). */
	public const HOW_DISMISSED = 'dismissed';

	/** How many months the trend covers, ending with the period's month. */
	public const TREND_MONTHS = 12;

	/** How many entries a report reads at most. */
	public const MAX_SCAN = 50000;

	private const DAY_SECONDS = 86400;

	/**
	 * @param AuditEntryMapper      $mapper    Reads the audit table.
	 * @param IAppConfig            $appConfig Reads the retention window.
	 * @param SecurityUpdateMatcher $matcher   The D4 join.
	 */
	public function __construct(
		private readonly AuditEntryMapper $mapper,
		private readonly IAppConfig $appConfig,
		private readonly SecurityUpdateMatcher $matcher = new SecurityUpdateMatcher(),
	) {
	}

	/**
	 * The report for a period, read from the audit history.
	 *
	 * @param AuditPeriod       $period The period; its upper bound is required.
	 * @param DateTimeImmutable $now    The moment the report is made.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
	 */
	public function report(AuditPeriod $period, DateTimeImmutable $now): array {
		$retentionDays = $this->retentionDays();
		$retentionCut  = $now->setTimezone(new DateTimeZone('UTC'))->modify('-' . $retentionDays . ' days')->format(AuditPeriod::FORMAT);
		$first         = $this->mapper->findBetween('1970-01-01 00:00:00', null, null, AdvisoryExposureTracker::OPERATION_OPEN, 1);
		$entries       = $this->mapper->findBetween($retentionCut, null, null, null, self::MAX_SCAN);

		return $this->compute(
			entries: $entries,
			period: $period,
			context: [
				'deadlines' => $this->deadlines(),
				'retentionDays' => $retentionDays,
				'retentionCut' => $retentionCut,
				'trackingSince' => ($first[0] ?? null)?->getCreatedAt(),
			],
		);
	}

	/**
	 * The deadline in days per severity, or null where none is set.
	 *
	 * The patch policy that sets them (`autoupdate-security-first`) does not
	 * record deadlines yet, so every severity reads null and the page says to
	 * set one there.
	 *
	 * @return array<string, int|null>
	 *
	 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
	 */
	public function deadlines(): array {
		return array_fill_keys(self::SEVERITIES, null);
	}

	/**
	 * The report over loaded entries.
	 *
	 * @param list<AuditEntry>     $entries The entries from the retention cut on.
	 * @param AuditPeriod          $period  The period.
	 * @param array<string, mixed> $context deadlines, retentionDays, retentionCut, trackingSince.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
	 */
	public function compute(array $entries, AuditPeriod $period, array $context): array {
		$sorted    = SecurityUpdateMatcher::sorted(entries: $entries);
		$pairs     = $this->pairs(sorted: $sorted);
		$deadlines = $context['deadlines'];
		$toUtc     = (string)$period->toUtc;

		$severities = [];
		foreach (self::SEVERITIES as $severity) {
			$own = array_filter($pairs, static fn (array $pair): bool => $pair['severity'] === $severity);
			$severities[$severity] = $this->figures(pairs: $own, period: $period, deadline: $deadlines[$severity] ?? null);
		}

		return [
			'from' => $period->fromUtc,
			'to' => $toUtc,
			'securityUpdates' => $this->securityUpdates(sorted: $sorted, period: $period),
			'installs' => $this->installs(sorted: $sorted, period: $period),
			'severities' => $severities,
			'trend' => $this->trend(pairs: $pairs, toUtc: $toUtc, context: $context),
			'deadlines' => $deadlines,
			'trackingSince' => $context['trackingSince'],
			'retentionDays' => $context['retentionDays'],
		];
	}

	/**
	 * Every exposure as a pair of its opening and, when it ended, its resolution.
	 *
	 * @param list<AuditEntry> $sorted The entries, oldest first.
	 *
	 * @return list<array{severity: string, openedAt: string|null, resolvedAt: string|null, how: string|null, timed: bool}>
	 */
	private function pairs(array $sorted): array {
		$pairs = [];
		$open  = [];
		foreach ($sorted as $entry) {
			$operation = $entry->getOperation();
			if ($operation !== AdvisoryExposureTracker::OPERATION_OPEN && $operation !== AdvisoryExposureTracker::OPERATION_RESOLVED) {
				continue;
			}

			foreach ($entry->decodedAdvisories() ?? [] as $advisory) {
				$key = $entry->getAppId() . '|' . (string)($advisory['id'] ?? '');
				if ($operation === AdvisoryExposureTracker::OPERATION_OPEN) {
					if (isset($open[$key]) === false) {
						$open[$key] = count($pairs);
						$pairs[] = [
							'severity' => self::severity(value: $advisory['severity'] ?? null),
							'openedAt' => $entry->getCreatedAt(),
							'resolvedAt' => null,
							'how' => null,
							'timed' => $entry->getMessage() !== AdvisoryExposureTracker::TRACKING_STARTED,
						];
					}

					continue;
				}

				if (isset($open[$key]) === true) {
					$pairs[$open[$key]]['resolvedAt'] = $entry->getCreatedAt();
					$pairs[$open[$key]]['how'] = $entry->getMessage();
					unset($open[$key]);
					continue;
				}

				// Opened before the retention cut: a resolution with no known start.
				$pairs[] = [
					'severity' => self::severity(value: $advisory['severity'] ?? null),
					'openedAt' => null,
					'resolvedAt' => $entry->getCreatedAt(),
					'how' => $entry->getMessage(),
					'timed' => false,
				];
			}
		}

		return $pairs;
	}

	/**
	 * The figures of one severity's pairs for a period.
	 *
	 * @param array<int, array<string, mixed>> $pairs    The pairs.
	 * @param AuditPeriod                      $period   The period.
	 * @param int|null                         $deadline Days, or null when none is set.
	 *
	 * @return array<string, int|float|null>
	 */
	private function figures(array $pairs, AuditPeriod $period, ?int $deadline): array {
		$opened = 0;
		$byUpdate = 0;
		$byDismissal = 0;
		$within = 0;
		$timedResolved = 0;
		$openPast = 0;
		$days = [];
		$end = (string)$period->toUtc;
		foreach ($pairs as $pair) {
			if ($pair['openedAt'] !== null && $period->contains(createdAt: $pair['openedAt']) === true) {
				$opened++;
			}

			if (self::isFixed(pair: $pair) === true && $period->contains(createdAt: (string)$pair['resolvedAt']) === true) {
				$byUpdate += (int)($pair['how'] === AdvisoryExposureTracker::HOW_UPDATE);
				$byDismissal += (int)($pair['how'] === self::HOW_DISMISSED);
				if ($pair['timed'] === true) {
					$timedResolved++;
					$days[] = self::daysBetween(from: (string)$pair['openedAt'], to: (string)$pair['resolvedAt']);
					$within += (int)($deadline !== null && self::byDeadline(pair: $pair, deadline: $deadline, moment: (string)$pair['resolvedAt']) === true);
				}
			}

			if ($deadline !== null && $pair['timed'] === true && $pair['openedAt'] <= $end
				&& ($pair['resolvedAt'] === null || $pair['resolvedAt'] > $end)
				&& self::byDeadline(pair: $pair, deadline: $deadline, moment: $end) === false
			) {
				$openPast++;
			}
		}

		return [
			'opened' => $opened,
			'resolvedByUpdate' => $byUpdate,
			'resolvedByDismissal' => $byDismissal,
			'resolvedWithinDeadline' => $deadline === null ? null : $within,
			'withinDeadlineShare' => ($deadline === null || $timedResolved === 0) ? null : round($within / $timedResolved, 4),
			'openPastDeadline' => $deadline === null ? null : $openPast,
			// Dismissals arrive with advisories-triage; until then none is active.
			'openExceptions' => 0,
			'medianDays' => self::median(values: $days),
		];
	}

	/**
	 * The share within the deadline and the median days per calendar month
	 * (UTC), for the twelve months ending with the period's month, cut to the
	 * retention window.
	 *
	 * @param list<array<string, mixed>> $pairs   The pairs.
	 * @param string                     $toUtc   The period's end.
	 * @param array<string, mixed>       $context deadlines and retentionCut.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function trend(array $pairs, string $toUtc, array $context): array {
		$utc   = new DateTimeZone('UTC');
		$month = (new DateTimeImmutable(substr($toUtc, 0, 7) . '-01 00:00:00', $utc));
		$trend = [];
		for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
			$start = $month->modify('-' . $i . ' months');
			$end   = $start->modify('+1 month')->modify('-1 second');
			if ($end->format(AuditPeriod::FORMAT) < $context['retentionCut']) {
				continue;
			}

			$trend[] = ['month' => $start->format('Y-m')] + $this->monthFigures(
				pairs: $pairs,
				period: new AuditPeriod(fromUtc: $start->format(AuditPeriod::FORMAT), toUtc: $end->format(AuditPeriod::FORMAT)),
				deadlines: $context['deadlines'],
			);
		}

		return $trend;
	}

	/**
	 * One month of the trend over all severities.
	 *
	 * @param list<array<string, mixed>> $pairs     The pairs.
	 * @param AuditPeriod                $period    The month.
	 * @param array<string, int|null>    $deadlines Days per severity.
	 *
	 * @return array{opened: int, resolved: int, withinDeadlineShare: float|null, medianDays: float|null}
	 */
	private function monthFigures(array $pairs, AuditPeriod $period, array $deadlines): array {
		$opened = 0;
		$resolved = 0;
		$counted = 0;
		$within = 0;
		$days = [];
		foreach ($pairs as $pair) {
			$opened += (int)($pair['openedAt'] !== null && $period->contains(createdAt: $pair['openedAt']) === true);
			if (self::isFixed(pair: $pair) === false || $period->contains(createdAt: (string)$pair['resolvedAt']) === false) {
				continue;
			}

			$resolved++;
			if ($pair['timed'] === false) {
				continue;
			}

			$days[] = self::daysBetween(from: (string)$pair['openedAt'], to: (string)$pair['resolvedAt']);
			$deadline = $deadlines[$pair['severity']] ?? null;
			if ($deadline !== null) {
				$counted++;
				$within += (int)self::byDeadline(pair: $pair, deadline: $deadline, moment: (string)$pair['resolvedAt']);
			}
		}

		return [
			'opened' => $opened,
			'resolved' => $resolved,
			'withinDeadlineShare' => $counted === 0 ? null : round($within / $counted, 4),
			'medianDays' => self::median(values: $days),
		];
	}

	/**
	 * The period's security updates, serialized with their fixed advisories.
	 *
	 * @param list<AuditEntry> $sorted The entries, oldest first.
	 * @param AuditPeriod      $period The period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function securityUpdates(array $sorted, AuditPeriod $period): array {
		$rows = [];
		foreach ($this->matcher->match(entries: $sorted) as $match) {
			if ($period->contains(createdAt: $match['entry']->getCreatedAt()) === true) {
				$rows[] = $match['entry']->jsonSerialize() + ['fixedAdvisories' => $match['fixedAdvisories']];
			}
		}

		return $rows;
	}

	/**
	 * The period's install entries counted by status.
	 *
	 * @param list<AuditEntry> $sorted The entries.
	 * @param AuditPeriod      $period The period.
	 *
	 * @return array<string, int>
	 */
	private function installs(array $sorted, AuditPeriod $period): array {
		$counts = [];
		foreach ($sorted as $entry) {
			if ($entry->getOperation() === 'install' && $period->contains(createdAt: $entry->getCreatedAt()) === true) {
				$counts[$entry->getStatus()] = ($counts[$entry->getStatus()] ?? 0) + 1;
			}
		}

		ksort($counts);
		return $counts;
	}

	/**
	 * The retention window in days, at least the prune job's floor.
	 *
	 * @return int
	 */
	private function retentionDays(): int {
		$days = $this->appConfig->getValueInt(Application::APP_ID, PruneAuditJob::CONFIG_KEY_RETENTION_DAYS, PruneAuditJob::DEFAULT_RETENTION_DAYS);
		return max(PruneAuditJob::MINIMUM_RETENTION_DAYS, $days);
	}

	/**
	 * Whether a pair ended by update or dismissal.
	 *
	 * @param array<string, mixed> $pair The pair.
	 *
	 * @return bool
	 */
	private static function isFixed(array $pair): bool {
		return $pair['resolvedAt'] !== null
			&& in_array($pair['how'], [AdvisoryExposureTracker::HOW_UPDATE, self::HOW_DISMISSED], true);
	}

	/**
	 * Whether a moment is no later than the pair's opening plus the deadline.
	 *
	 * @param array<string, mixed> $pair     The pair.
	 * @param int                  $deadline Days.
	 * @param string               $moment   `Y-m-d H:i:s` UTC.
	 *
	 * @return bool
	 */
	private static function byDeadline(array $pair, int $deadline, string $moment): bool {
		return self::daysBetween(from: (string)$pair['openedAt'], to: $moment) <= $deadline;
	}

	/**
	 * Days between two stored timestamps.
	 *
	 * @param string $from `Y-m-d H:i:s` UTC.
	 * @param string $to   `Y-m-d H:i:s` UTC.
	 *
	 * @return float
	 */
	private static function daysBetween(string $from, string $to): float {
		$utc = new DateTimeZone('UTC');
		$seconds = (new DateTimeImmutable($to, $utc))->getTimestamp() - (new DateTimeImmutable($from, $utc))->getTimestamp();
		return $seconds / self::DAY_SECONDS;
	}

	/**
	 * The median, rounded to one decimal, or null for none.
	 *
	 * @param list<float> $values The values.
	 *
	 * @return float|null
	 */
	private static function median(array $values): ?float {
		if ($values === []) {
			return null;
		}

		sort($values);
		$count = count($values);
		$middle = intdiv($count, 2);
		$median = ($count % 2 === 1) ? $values[$middle] : (($values[$middle - 1] + $values[$middle]) / 2);
		return round($median, 1);
	}

	/**
	 * A severity the report lists, `unknown` for anything else.
	 *
	 * @param mixed $value The stored severity.
	 *
	 * @return string
	 */
	private static function severity(mixed $value): string {
		$severity = strtolower(trim((string)$value));
		return in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown';
	}
}
