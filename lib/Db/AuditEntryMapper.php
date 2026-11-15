<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @psalm-api
 *
 * @extends QBMapper<AuditEntry>
 */
class AuditEntryMapper extends QBMapper {
	/**
	 * FROZEN ON THE OLD APP ID — deliberately still `app_versions_audit`.
	 *
	 * Same reason as {@see PatMapper::TABLE_NAME}: table names are not keyed
	 * by app id, so the `app_versions` -> `versioniq` rename leaves this table
	 * exactly where it is, with the whole audit history in it. The audit trail
	 * is append-only and is the record of who installed what — orphaning it
	 * behind a renamed constant would destroy evidence, silently, while every
	 * new write went to an empty table.
	 *
	 * @var string
	 */
	public const TABLE_NAME = 'app_versions_audit';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, AuditEntry::class);
	}

	/**
	 * Newest-first page of audit entries, optionally filtered by app id;
	 * see "Audit entries are immutable and admin-readable".
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @return list<AuditEntry>
	 */
	public function findPage(?string $appId, int $limit, int $offset): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		if ($appId !== null && $appId !== '') {
			$qb->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * The newest successful entry for an app that brought it to the given
	 * version, or null; the manifest's `runningSince` fallback
	 * (install-multi-instance-promotion design D3).
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/design.md
	 */
	public function findNewestSuccessTo(string $appId, string $toVersion): ?AuditEntry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('app_id', $qb->createNamedParameter($appId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('to_version', $qb->createNamedParameter($toVersion, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('success', IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);

		$entries = $this->findEntities($qb);

		return $entries[0] ?? null;
	}

	/**
	 * Deletes entries older than the cutoff in batches, oldest first; see "Retention".
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function deleteOlderThan(\DateTimeImmutable $cutoff, int $batchSize): int {
		$selectQb = $this->db->getQueryBuilder();
		$selectQb->select('id')
			->from($this->tableName)
			->where($selectQb->expr()->lt(
				'created_at',
				$selectQb->createNamedParameter($cutoff->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)
			))
			->orderBy('created_at', 'ASC')
			->setMaxResults($batchSize);

		$result = $selectQb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			$ids[] = (int)$id;
		}
		$result->closeCursor();

		if ($ids === []) {
			return 0;
		}

		$deleteQb = $this->db->getQueryBuilder();
		$deleteQb->delete($this->tableName)
			->where($deleteQb->expr()->in('id', $deleteQb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		return $deleteQb->executeStatement();
	}
}
