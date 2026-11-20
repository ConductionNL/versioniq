<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Records when each advisory affected the instance and how it ended, and
 * server upgrades, as audit entries (audit-security-reports D1, D3).
 *
 * Exposure is tracked by comparing advisory snapshots. The open pairs
 * (`{target}|{advisoryId}`) are working state in app config; the audit
 * entries are the record:
 *
 * - a pair active in the new snapshot and not open yet is opened with an
 *   `advisory_open` entry (on the very first run with the message
 *   `tracking started`, because its real start is unknown);
 * - an open pair no longer active is resolved with an `advisory_resolved`
 *   entry whose message says how: `update` when the installed version
 *   changed, `withdrawn` when the advisory is gone from the target's
 *   history, `not_affected` otherwise. An app the check could not reach
 *   keeps its pairs open, so a failing source never counts as a fix;
 * - a changed server version is recorded as `server_update`.
 *
 * Dismissal as a way to resolve (`dismissed`) arrives with
 * `advisories-triage`, which does not exist yet.
 *
 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
 */
class AdvisoryExposureTracker {
	/**
	 * App config key of the open pairs.
	 */
	public const CONFIG_EXPOSURES = 'advisory.exposures';

	/**
	 * App config key of the server version seen by the last check.
	 */
	public const CONFIG_SERVER_VERSION = 'advisory.last_server_version';

	public const OPERATION_OPEN = 'advisory_open';
	public const OPERATION_RESOLVED = 'advisory_resolved';
	public const OPERATION_SERVER_UPDATE = 'server_update';

	public const HOW_UPDATE = 'update';
	public const HOW_WITHDRAWN = 'withdrawn';
	public const HOW_NOT_AFFECTED = 'not_affected';

	public const TRACKING_STARTED = 'tracking started';

	private const ACTOR = 'system';

	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private ServerVersionProvider $serverVersion,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Compare a new advisory snapshot with the open pairs and record what changed.
	 *
	 * @param array<string, array{appId?: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string}>, error: ?string}> $snapshot
	 *                                                                                                                                                                       The correlations, keyed by target (an app id, or `:server`).
	 * @param int $checkedAt Unix time of the check.
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
	 */
	public function track(array $snapshot, int $checkedAt): void {
		$this->trackServerVersion();

		$stored = $this->loadExposures();
		$firstRun = ($stored === null);
		$open = $stored ?? [];
		$active = $this->activePairs($snapshot);

		foreach ($active as $key => $pair) {
			if (isset($open[$key])) {
				continue;
			}

			$this->record(
				target: $pair['target'],
				operation: self::OPERATION_OPEN,
				versions: new AuditedVersions(null, $pair['version'], null),
				message: $firstRun ? self::TRACKING_STARTED : null,
				advisory: $pair,
			);
			$open[$key] = [
				'openedAt' => $checkedAt,
				'severity' => $pair['severity'],
				'version' => $pair['version'],
				'trackingStarted' => $firstRun,
			];
		}

		foreach ($open as $key => $exposure) {
			if (isset($active[$key])) {
				continue;
			}

			[$target, $advisoryId] = explode('|', $key, 2);
			$row = ($snapshot[$target] ?? null);
			if (is_array($row) && ($row['error'] ?? null) !== null) {
				// Not reached this time: never count a failure as a fix.
				continue;
			}

			$installed = is_array($row) ? ($row['installedVersion'] ?? null) : null;
			$this->record(
				target: $target,
				operation: self::OPERATION_RESOLVED,
				versions: new AuditedVersions($exposure['version'] ?? null, $installed, null),
				message: $this->howResolved(exposure: $exposure, advisoryId: $advisoryId, row: $row),
				advisory: ['id' => $advisoryId, 'severity' => (string)($exposure['severity'] ?? AdvisorySeverity::UNKNOWN)],
			);
			unset($open[$key]);
		}

		$this->storeExposures($open);
	}

	/**
	 * The advisory/target pairs that affect the installed version now.
	 *
	 * @param array<string, mixed> $snapshot The correlations.
	 *
	 * @return array<string, array{target: string, id: string, severity: string, version: ?string}>
	 */
	private function activePairs(array $snapshot): array {
		$pairs = [];
		foreach ($snapshot as $target => $row) {
			if (!is_array($row) || ($row['error'] ?? null) !== null || ($row['state'] ?? '') !== AdvisoryService::STATE_VULNERABLE) {
				continue;
			}

			foreach (($row['advisories'] ?? []) as $advisory) {
				$id = (string)($advisory['id'] ?? '');
				if ($id === '') {
					continue;
				}

				$pairs[$target . '|' . $id] = [
					'target' => (string)$target,
					'id' => $id,
					'severity' => (string)($advisory['severity'] ?? AdvisorySeverity::UNKNOWN),
					'version' => $row['installedVersion'] ?? null,
				];
			}
		}

		return $pairs;
	}

	/**
	 * How an open pair ended.
	 *
	 * @param array<string, mixed>      $exposure   The open pair.
	 * @param string                    $advisoryId The advisory.
	 * @param array<string, mixed>|null $row        The target's row now, null when it is gone.
	 *
	 * @return string `update`, `withdrawn` or `not_affected`.
	 */
	private function howResolved(array $exposure, string $advisoryId, ?array $row): string {
		if ($row !== null && ($row['installedVersion'] ?? null) !== ($exposure['version'] ?? null)) {
			return self::HOW_UPDATE;
		}

		$history = array_map(
			static fn (array $advisory): string => (string)($advisory['id'] ?? ''),
			($row['advisories'] ?? [])
		);

		return in_array($advisoryId, $history, true) ? self::HOW_NOT_AFFECTED : self::HOW_WITHDRAWN;
	}

	/**
	 * Record a changed server version; the first run only remembers it.
	 *
	 * @spec openspec/changes/audit-security-reports/specs/audit-trail/spec.md#requirement-advisory-exposure-and-server-updates-are-recorded-in-the-history
	 */
	private function trackServerVersion(): void {
		$current = $this->serverVersion->current();
		$last = $this->config->getValueString(Application::APP_ID, self::CONFIG_SERVER_VERSION, '');
		if ($last === $current) {
			return;
		}

		if ($last !== '') {
			$this->auditLogger->record(
				new AuditSubject(self::ACTOR, AdvisoryService::SERVER_KEY, self::OPERATION_SERVER_UPDATE),
				new AuditedVersions($last, $current, null),
				AuditLogger::STATUS_SUCCESS,
				'Detected by the advisory check',
			);
		}

		$this->config->setValueString(Application::APP_ID, self::CONFIG_SERVER_VERSION, $current);
	}

	/**
	 * Write one exposure entry.
	 *
	 * @param string                               $target    The app id or `:server`.
	 * @param string                               $operation The audit operation.
	 * @param AuditedVersions                      $versions  From and to version.
	 * @param string|null                          $message   How, or `tracking started`.
	 * @param array{id: string, severity: string} $advisory  The advisory.
	 */
	private function record(string $target, string $operation, AuditedVersions $versions, ?string $message, array $advisory): void {
		$this->auditLogger->record(
			new AuditSubject(self::ACTOR, $target, $operation),
			$versions,
			AuditLogger::STATUS_SUCCESS,
			$message,
			[['id' => $advisory['id'], 'severity' => $advisory['severity']]],
		);
	}

	/**
	 * The open pairs, or null before the first run.
	 *
	 * @return array<string, array<string, mixed>>|null
	 */
	private function loadExposures(): ?array {
		$raw = $this->config->getValueString(Application::APP_ID, self::CONFIG_EXPOSURES, '');
		if ($raw === '') {
			return null;
		}

		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('AdvisoryExposureTracker: the open exposures could not be read; starting over', ['message' => $error->getMessage()]);
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param array<string, array<string, mixed>> $open The open pairs.
	 */
	private function storeExposures(array $open): void {
		ksort($open);
		$this->config->setValueString(Application::APP_ID, self::CONFIG_EXPOSURES, json_encode((object)$open, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
	}
}
