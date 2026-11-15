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

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One immutable audit-trail row: a single version operation (install,
 * bind_source, …) performed through Versioniq, with who/what/when.
 *
 * There is no update path for this entity reachable from a controller — the
 * only writer is {@see \OCA\Versioniq\Service\Audit\AuditLogger::record()}
 * (insert-only) and the only deleter is the retention prune job.
 *
 * @psalm-api
 *
 * The inherited OCP\AppFramework\Db\Entity::$id is declared but not initialised
 * by the base constructor; it is populated by the mapper on insert/load.
 * @psalm-suppress PropertyNotSetInConstructor
 *
 * @method string getActorUid()
 * @method void setActorUid(string $value)
 * @method string getAppId()
 * @method void setAppId(string $value)
 * @method string getOperation()
 * @method void setOperation(string $value)
 * @method ?string getFromVersion()
 * @method void setFromVersion(?string $value)
 * @method ?string getToVersion()
 * @method void setToVersion(?string $value)
 * @method ?string getSourceId()
 * @method void setSourceId(?string $value)
 * @method string getStatus()
 * @method void setStatus(string $value)
 * @method ?string getMessage()
 * @method void setMessage(?string $value)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $value)
 * @method ?string getOrigin()
 * @method void setOrigin(?string $value)
 */
class AuditEntry extends Entity implements JsonSerializable {
	public const STATUS_SUCCESS = 'success';
	public const STATUS_FAILURE = 'failure';

	protected string $actorUid = '';
	protected string $appId = '';
	protected string $operation = '';
	protected ?string $fromVersion = null;
	protected ?string $toVersion = null;
	protected ?string $sourceId = null;
	protected string $status = '';
	protected ?string $message = null;
	protected string $createdAt = '';
	/** Where the operation came from (web, cli, job, listener); null on rows written before it was recorded. */
	protected ?string $origin = null;

	public function __construct() {
		$this->addType('actorUid', 'string');
		$this->addType('appId', 'string');
		$this->addType('operation', 'string');
		$this->addType('fromVersion', 'string');
		$this->addType('toVersion', 'string');
		$this->addType('sourceId', 'string');
		$this->addType('status', 'string');
		$this->addType('message', 'string');
		$this->addType('createdAt', 'string');
		$this->addType('origin', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'actorUid' => $this->actorUid,
			'appId' => $this->appId,
			'operation' => $this->operation,
			'fromVersion' => $this->fromVersion,
			'toVersion' => $this->toVersion,
			'sourceId' => $this->sourceId,
			'status' => $this->status,
			'message' => $this->message,
			'createdAt' => $this->createdAt,
			'origin' => $this->origin,
		];
	}
}
