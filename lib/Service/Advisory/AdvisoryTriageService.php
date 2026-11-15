<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Records a human decision on one stored advisory: dismiss, reopen or assign
 * (advisories-triage, design D2-D4). Each refuses an advisory the stored
 * snapshot does not hold for the app, stores the decision in the triage map
 * next to the snapshot, never inside it, and writes one audit entry with the
 * installed version as `to_version`.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
 *
 * @psalm-api
 */
class AdvisoryTriageService {
	public function __construct(
		private AdvisoryResultStore $results,
		private AdvisoryTriageStore $store,
		private AdvisoryOwners $owners,
		private AuditLogger $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Dismisses an advisory for the installed version.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @return array<string, array<string, mixed>> The triage map after the change.
	 * @throws TriageRefusal When the snapshot does not hold the advisory.
	 * @throws \JsonException When the map cannot be stored.
	 */
	public function dismiss(TriageTarget $target, Dismissal $dismissal): array {
		$installed = $this->installedVersion(target: $target);
		$triage = $this->store->read();
		$entry = $triage[$target->key()] ?? [];
		$entry['dismissal'] = [
			'reason' => $dismissal->reason,
			'comment' => $dismissal->comment,
			'by' => $target->actor,
			'at' => $this->now(),
			'until' => $dismissal->until,
			'installedVersion' => $installed,
		];

		$triage = $this->write(triage: $triage, key: $target->key(), entry: $entry);
		$message = $target->advisoryId . ': ' . $dismissal->reason . ($dismissal->comment !== '' ? ': ' . $dismissal->comment : '');
		$this->record(target: $target, operation: AuditLogger::OPERATION_ADVISORY_DISMISS, message: $message, installed: $installed);

		return $triage;
	}

	/**
	 * Reopens a dismissed advisory, so it counts again.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @return array<string, array<string, mixed>> The triage map after the change.
	 * @throws TriageRefusal When the snapshot does not hold the advisory.
	 * @throws \JsonException When the map cannot be stored.
	 */
	public function reopen(TriageTarget $target): array {
		$installed = $this->installedVersion(target: $target);
		$triage = $this->store->read();
		$entry = $triage[$target->key()] ?? [];
		unset($entry['dismissal']);

		$triage = $this->write(triage: $triage, key: $target->key(), entry: $entry);
		$this->record(target: $target, operation: AuditLogger::OPERATION_ADVISORY_REOPEN, message: $target->advisoryId, installed: $installed);

		return $triage;
	}

	/**
	 * Assigns an advisory to `user:{uid}` or `group:{gid}`, or unassigns it
	 * when $assignee is empty, and notifies the new owners.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-assigns-an-advisory-to-an-owner
	 * @return array<string, array<string, mixed>> The triage map after the change.
	 * @throws TriageRefusal When the snapshot does not hold the advisory, or the assignee is not acceptable.
	 * @throws \JsonException When the map cannot be stored.
	 */
	public function assign(TriageTarget $target, string $assignee): array {
		$installed = $this->installedVersion(target: $target);
		$owner = $this->owners->parse($assignee);
		$triage = $this->store->read();
		$entry = array_diff_key($triage[$target->key()] ?? [], ['assignee' => true, 'assignedBy' => true, 'assignedAt' => true]);
		if ($owner !== null) {
			$entry += ['assignee' => $owner, 'assignedBy' => $target->actor, 'assignedAt' => $this->now()];
		}

		$triage = $this->write(triage: $triage, key: $target->key(), entry: $entry);
		$message = $target->advisoryId . ': ' . ($owner === null ? 'unassigned' : $owner['type'] . ':' . $owner['id']);
		$this->record(target: $target, operation: AuditLogger::OPERATION_ADVISORY_ASSIGN, message: $message, installed: $installed);
		if ($owner !== null) {
			$this->owners->notify($owner, $target);
		}

		return $triage;
	}

	/**
	 * The installed version on the snapshot row that holds the advisory.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @throws TriageRefusal When no row of the app holds it.
	 */
	private function installedVersion(TriageTarget $target): ?string {
		$row = $this->results->read()['advisories'][$target->appId] ?? null;
		if (is_array($row) === false || self::holds(row: $row, advisoryId: $target->advisoryId) === false) {
			throw TriageRefusal::notFound();
		}

		if (isset($row['installedVersion']) === true && is_string($row['installedVersion']) === true) {
			return $row['installedVersion'];
		}

		return null;
	}

	/**
	 * Whether a snapshot row lists the advisory.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<array-key, mixed> $row
	 */
	private static function holds(array $row, string $advisoryId): bool {
		/** @var mixed $advisories */
		$advisories = $row['advisories'] ?? null;
		if (is_array($advisories) === false) {
			return false;
		}

		foreach (array_keys($advisories) as $index) {
			if (is_array($advisories[$index]) === true && ($advisories[$index]['id'] ?? null) === $advisoryId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stores the map with the entry set, or without it once it holds no decision.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 * @param array<string, array<string, mixed>> $triage
	 * @param array<string, mixed> $entry
	 * @return array<string, array<string, mixed>>
	 * @throws \JsonException When the map cannot be stored.
	 */
	private function write(array $triage, string $key, array $entry): array {
		unset($triage[$key]);
		if (isset($entry['dismissal']) === true || isset($entry['assignee']) === true) {
			$triage[$key] = $entry;
		}

		$this->store->write($triage);

		return $triage;
	}

	/**
	 * Writes the audit entry of one decision.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	private function record(TriageTarget $target, string $operation, string $message, ?string $installed): void {
		$this->audit->record(
			new AuditSubject($target->actor, $target->appId, $operation),
			new AuditedVersions(null, $installed, null),
			AuditLogger::STATUS_SUCCESS,
			$message,
		);
	}

	/**
	 * The current time as ISO 8601.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	private function now(): string {
		return gmdate(DATE_ATOM, $this->time->getTime());
	}
}
