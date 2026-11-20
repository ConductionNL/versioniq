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

use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Db\AuditEntryMapper;

/**
 * The History tab's period and security filters on `GET /api/audit`
 * (audit-security-reports D5): entries of a period, newest first, or with
 * `securityOnly` the installs and server updates that fixed an advisory,
 * each with its fixed advisories.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
 */
class AuditPeriodQuery {
	/**
	 * How many entries a security-only query reads at most to join installs
	 * with their resolutions. Bounded by retention in practice.
	 */
	public const MAX_SCAN = 20000;

	/**
	 * @param AuditEntryMapper      $mapper  Reads the audit table.
	 * @param SecurityUpdateMatcher $matcher The D4 join.
	 */
	public function __construct(
		private readonly AuditEntryMapper $mapper,
		private readonly SecurityUpdateMatcher $matcher = new SecurityUpdateMatcher(),
	) {
	}

	/**
	 * One page of the period's entries.
	 *
	 * @param AuditPeriod                                                           $period The period.
	 * @param array{appId: string, operation: string, securityOnly: bool}             $filter The filters.
	 * @param array{limit: int, offset: int}                                          $page   The page.
	 *
	 * @return array{entries: list<array<string, mixed>>, limit: int, offset: int, total: int|null}
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
	 */
	public function page(AuditPeriod $period, array $filter, array $page): array {
		$appId     = $filter['appId'] !== '' ? $filter['appId'] : null;
		$operation = $filter['operation'] !== '' ? $filter['operation'] : null;

		if ($filter['securityOnly'] === false) {
			$entries = $this->mapper->findBetween($period->fromUtc, $period->toUtc, $appId, $operation, $page['limit'], $page['offset'], true);
			return [
				'entries' => array_map(static fn (AuditEntry $entry): array => $entry->jsonSerialize(), $entries),
				'limit' => $page['limit'],
				'offset' => $page['offset'],
				'total' => null,
			];
		}

		$rows = $this->securityUpdates(period: $period, appId: $appId, operation: $operation);
		return [
			'entries' => array_slice(array_reverse($rows), $page['offset'], $page['limit']),
			'limit' => $page['limit'],
			'offset' => $page['offset'],
			'total' => count($rows),
		];
	}

	/**
	 * The period's security updates, oldest first, each serialized with
	 * `fixedAdvisories`. Resolutions after the period's end still count for an
	 * install inside it.
	 *
	 * @param AuditPeriod $period    The period.
	 * @param string|null $appId     Only this app.
	 * @param string|null $operation Only `install` or only `server_update`.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md
	 */
	public function securityUpdates(AuditPeriod $period, ?string $appId, ?string $operation): array {
		$entries = $this->mapper->findBetween($period->fromUtc, null, $appId, null, self::MAX_SCAN);
		$rows    = [];
		foreach ($this->matcher->match(entries: $entries) as $match) {
			$entry = $match['entry'];
			if ($period->contains(createdAt: $entry->getCreatedAt()) === false
				|| ($operation !== null && $entry->getOperation() !== $operation)
			) {
				continue;
			}

			$rows[] = $entry->jsonSerialize() + ['fixedAdvisories' => $match['fixedAdvisories']];
		}

		return $rows;
	}
}
