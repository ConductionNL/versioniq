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

/**
 * Writes the audit row for a change to the update rules (audit-attribution-and-forwarding
 * D2): an app's policy set or cleared, the automatic update switch or window, and
 * the advisory settings. Each row carries the value before and after, in the form
 * `field: before -> after`, and only the fields that changed.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
 */
final class RuleChangeAuditor {
	/** The app id the instance-wide settings rows are recorded under. */
	public const SETTINGS_APP_ID = 'versioniq';

	public function __construct(
		private AuditLogger $auditLogger,
	) {
	}

	/**
	 * An app's policy was set.
	 *
	 * @param string      $actorUid The admin.
	 * @param string      $appId    The app.
	 * @param string|null $before   The level before, or null when it had none.
	 * @param string      $after    The level now.
	 *
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
	 */
	public function policySet(string $actorUid, string $appId, ?string $before, string $after): void {
		$this->record(
			subject: new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_POLICY_SET),
			message: self::change('level', $before ?? 'none', $after),
		);
	}

	/**
	 * An app's policy was cleared.
	 *
	 * @param string      $actorUid The admin.
	 * @param string      $appId    The app.
	 * @param string|null $before   The level before, or null when it had none.
	 *
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
	 */
	public function policyCleared(string $actorUid, string $appId, ?string $before): void {
		$this->record(
			subject: new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_POLICY_CLEAR),
			message: self::change('level', $before ?? 'none', 'none'),
		);
	}

	/**
	 * The automatic update switch or window changed; nothing is written when neither did.
	 *
	 * @param string                                $actorUid The admin.
	 * @param array{enabled: bool, window: string} $before   The settings before.
	 * @param array{enabled: bool, window: string} $after    The settings now.
	 *
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
	 */
	public function autoUpdateSettings(string $actorUid, array $before, array $after): void {
		$this->recordSettings(
			subject: new AuditSubject($actorUid, self::SETTINGS_APP_ID, AuditLogger::OPERATION_AUTO_UPDATE_SETTINGS),
			before: $before,
			after: $after,
		);
	}

	/**
	 * The advisory check interval or the digest switch changed; nothing is written when neither did.
	 *
	 * @param string                                     $actorUid The admin.
	 * @param array{intervalHours: int, digestEnabled: bool} $before The settings before.
	 * @param array{intervalHours: int, digestEnabled: bool} $after  The settings now.
	 *
	 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-changes-to-the-update-rules-are-audited
	 */
	public function advisorySettings(string $actorUid, array $before, array $after): void {
		$this->recordSettings(
			subject: new AuditSubject($actorUid, self::SETTINGS_APP_ID, AuditLogger::OPERATION_ADVISORY_SETTINGS),
			before: $before,
			after: $after,
		);
	}

	/**
	 * One `field: before -> after` per changed field, or nothing when none changed.
	 *
	 * @param AuditSubject                     $subject Who changed what.
	 * @param array<string, bool|int|string> $before  The values before.
	 * @param array<string, bool|int|string> $after   The values now.
	 */
	private function recordSettings(AuditSubject $subject, array $before, array $after): void {
		$changes = [];
		foreach ($after as $field => $value) {
			$old = $before[$field] ?? null;
			if ($old !== $value) {
				$changes[] = self::change($field, self::text($old), self::text($value));
			}
		}

		if ($changes !== []) {
			$this->record(subject: $subject, message: implode(', ', $changes));
		}
	}

	private function record(AuditSubject $subject, string $message): void {
		$this->auditLogger->record($subject, new AuditedVersions(null, null, null), AuditLogger::STATUS_SUCCESS, $message);
	}

	private static function change(string $field, string $before, string $after): string {
		return $field . ': ' . $before . ' -> ' . $after;
	}

	private static function text(bool|int|string|null $value): string {
		return match (true) {
			$value === null => 'none',
			is_bool($value) => $value ? 'true' : 'false',
			$value === '' => 'none',
			default => (string)$value,
		};
	}
}
