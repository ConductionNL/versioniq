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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @psalm-api
 *
 * @extends QBMapper<Pat>
 */
class PatMapper extends QBMapper {
	/**
	 * Renamed from the pre-rename table name on 2026-09-08.
	 *
	 * Database table names are not keyed by the Nextcloud app id, so the
	 * `app_versions` -> `versioniq` rename did not move this table on its own.
	 * The consolidated migration Version1100Date20260908000000 renames it in
	 * place before the schema comparison runs, so every stored PAT survives the move.
	 * This constant and the literals in that migration are the same identifier
	 * on both sides and move together.
	 *
	 * @var string
	 */
	public const TABLE_NAME = 'versioniq_pats';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, Pat::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Pat {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Returns every stored PAT regardless of owner; see "PAT expiry warnings"
	 * (the daily job must sweep all tokens, not just those visible to a uid).
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return list<Pat>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		return $this->findEntities($qb);
	}

	/**
	 * @return list<Pat>
	 */
	public function findVisibleTo(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->orX(
				$qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('shared_with_admins', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	public function deleteByOwner(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
