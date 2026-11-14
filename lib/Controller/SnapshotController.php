<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Controller;

use OCA\Versioniq\Service\Snapshot\RollbackRunLog;
use OCA\Versioniq\Service\Snapshot\SnapshotPlans;
use OCA\Versioniq\Service\Snapshot\SnapshotStore;
use OCA\Versioniq\Service\Snapshot\SnapshotTaker;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Snapshots of every app's version and the plan to roll back to one
 * (install-bulk-rollback design D2-D4). Admin-only twice over: the routes
 * carry no NoAdminRequired attribute, and isAdmin() is the explicit guard.
 * Every write requires a password confirmation.
 *
 * @psalm-suppress UnusedClass
 */
class SnapshotController extends OCSController {
	/** The reasons a caller of the API may give; the jobs set the others. */
	private const API_REASONS = ['manual', 'before_rollback'];

	public function __construct(
		string $appName,
		IRequest $request,
		private SnapshotStore $store,
		private SnapshotTaker $taker,
		private SnapshotPlans $plans,
		private RollbackRunLog $runLog,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Lists the snapshots, newest first; with `at`, also picks the newest one taken on or before that date
	 *
	 * @param string $at A date (YYYY-MM-DD); empty lists only
	 *
	 * @return DataResponse<Http::STATUS_OK, array{snapshots: list<array<string, mixed>>, picked: array<string, mixed>|null}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The snapshots, and the picked one or null
	 * 400: The date is not a YYYY-MM-DD date
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/snapshots')]
	public function index(string $at = ''): DataResponse {
		if (!$this->isAdmin()) {
			return $this->forbidden();
		}

		$picked = null;
		if ($at !== '') {
			$moment = SnapshotStore::endOfDay($at);
			if ($moment === null) {
				return new DataResponse(['message' => 'The date must be YYYY-MM-DD'], Http::STATUS_BAD_REQUEST);
			}
			$picked = $this->store->latestAtOrBefore($moment)?->summary();
		}

		return new DataResponse(['snapshots' => $this->store->index(), 'picked' => $picked]);
	}

	/**
	 * Takes a snapshot of every managed app now
	 *
	 * @param string $name An optional name; a named snapshot is kept until deleted
	 * @param string $reason `manual`, or `before_rollback` right before a rollback run
	 *
	 * @return DataResponse<Http::STATUS_CREATED, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_CONFLICT, array{message: string}, array{}>
	 *
	 * 201: The snapshot's index line
	 * 400: Unknown reason
	 * 403: Caller is not an administrator
	 * 409: The named snapshot cap is reached
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/snapshots')]
	public function create(string $name = '', string $reason = 'manual'): DataResponse {
		if (!$this->isAdmin()) {
			return $this->forbidden();
		}
		if (!in_array($reason, self::API_REASONS, true)) {
			return new DataResponse(['message' => 'Unknown snapshot reason'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$snapshot = $this->taker->take($reason, $this->uid(), $name !== '' ? $name : null);
		} catch (\OverflowException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_CONFLICT);
		}

		return new DataResponse($snapshot->summary(), Http::STATUS_CREATED);
	}

	/**
	 * Deletes a snapshot
	 *
	 * @param string $id The snapshot id
	 *
	 * @return DataResponse<Http::STATUS_OK, array{deleted: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Deleted
	 * 403: Caller is not an administrator
	 * 404: No such snapshot
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/snapshots/{id}')]
	public function destroy(string $id): DataResponse {
		if (!$this->isAdmin()) {
			return $this->forbidden();
		}
		if (!$this->store->delete($id)) {
			return new DataResponse(['message' => 'Snapshot not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['deleted' => $id]);
	}

	/**
	 * The plan to roll the instance back to a snapshot, one step per app
	 *
	 * @param string $id The snapshot id
	 *
	 * @return DataResponse<Http::STATUS_OK, array{snapshot: array<string, mixed>, steps: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The snapshot and the steps
	 * 403: Caller is not an administrator
	 * 404: No such snapshot
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/snapshots/{id}/plan')]
	public function plan(string $id): DataResponse {
		if (!$this->isAdmin()) {
			return $this->forbidden();
		}
		$snapshot = $this->store->get($id);
		if ($snapshot === null) {
			return new DataResponse(['message' => 'Snapshot not found'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['snapshot' => $snapshot->summary(), 'steps' => $this->plans->planFor($snapshot)]);
	}

	/**
	 * Records the start or the end of a rollback run in the audit trail
	 *
	 * @param string $id The snapshot id
	 * @param string $phase `start` or `end`
	 * @param int $steps The number of steps the run will take (start)
	 * @param int $succeeded Steps that succeeded (end)
	 * @param int $failed Steps that failed (end)
	 * @param int $skipped Steps that were skipped (end)
	 *
	 * @return DataResponse<Http::STATUS_OK, array{recorded: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: Recorded
	 * 400: Unknown phase or a negative count
	 * 403: Caller is not an administrator
	 * 404: No such snapshot
	 *
	 * @spec openspec/changes/install-bulk-rollback/specs/migration-safety/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/snapshots/{id}/run-log')]
	public function runLog(string $id, string $phase, int $steps = 0, int $succeeded = 0, int $failed = 0, int $skipped = 0): DataResponse {
		if (!$this->isAdmin()) {
			return $this->forbidden();
		}
		if ($this->store->get($id) === null) {
			return new DataResponse(['message' => 'Snapshot not found'], Http::STATUS_NOT_FOUND);
		}
		if (min($steps, $succeeded, $failed, $skipped) < 0) {
			return new DataResponse(['message' => 'Step counts cannot be negative'], Http::STATUS_BAD_REQUEST);
		}

		if ($phase === 'start') {
			$this->runLog->started($this->uid(), $id, $steps);
		} elseif ($phase === 'end') {
			$this->runLog->finished($this->uid(), $id, $succeeded, $failed, $skipped);
		} else {
			return new DataResponse(['message' => 'The phase must be start or end'], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['recorded' => $phase]);
	}

	/**
	 * @return DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 */
	private function forbidden(): DataResponse {
		return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
