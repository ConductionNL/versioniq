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
	 * Renamed from the pre-rename table name on 2026-09-08.
	 *
	 * Database table names are not keyed by the Nextcloud app id, so the
	 * `app_versions` -> `versioniq` rename did not move this table on its own.
	 * The consolidated migration Version1100Date20260908000000 renames it in
	 * place before the schema comparison runs, so every audit row survives the move.
	 * This constant and the literals in that migration are the same identifier
	 * on both sides and move together.
	 *
	 * @var string
	 */
	public const TABLE_NAME = 'versioniq_audit';

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
