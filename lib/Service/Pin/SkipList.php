<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Pin;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The versions of an app an admin skipped, under app config key
 * `skip.{appId}`: `[{version, skippedBy, skippedAt, reason}]`, newest last,
 * at most MAX entries. A skipped version is never chosen by the nightly job
 * and never shown as the pending update; later versions arrive as usual.
 * Unlike the attempt ledger, a skip is an admin's decision: it survives a
 * Retry and is only removed by Unskip. Skip and unskip are audited.
 *
 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
 * @psalm-api
 */
class SkipList {
	/** The most versions one app keeps on its skip list; the oldest skip goes first. */
	public const MAX = 20;

	/** The longest reason kept. */
	public const MAX_REASON = 500;

	private const KEY_PREFIX = 'skip.';

	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The skip entries of an app, oldest first; a malformed value reads as none.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
	 * @return list<array{version: string, skippedBy: string, skippedAt: string, reason: string}>
	 */
	public function entries(string $appId): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$this->logger->warning('SkipList: malformed skip list, treating as empty', ['appId' => $appId]);
			return [];
		}

		$entries = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) && is_string($entry['version'] ?? null) && $entry['version'] !== '') {
				$entries[] = [
					'version' => $entry['version'],
					'skippedBy' => is_string($entry['skippedBy'] ?? null) ? $entry['skippedBy'] : '',
					'skippedAt' => is_string($entry['skippedAt'] ?? null) ? $entry['skippedAt'] : '',
					'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : '',
				];
			}
		}

		return $entries;
	}

	/**
	 * The skipped versions of an app.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
	 * @return list<string>
	 */
	public function versions(string $appId): array {
		return array_column($this->entries($appId), 'version');
	}

	/**
	 * Skip a version. Skipping a version already on the list keeps its first
	 * entry and records nothing again; the list keeps the newest MAX skips.
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
	 * @return bool True when the version was added, false when it was already skipped.
	 */
	public function skip(string $appId, string $version, string $actorUid, string $skippedAt, string $reason = ''): bool {
		$entries = $this->entries($appId);
		if (in_array($version, array_column($entries, 'version'), true)) {
			return false;
		}

		$reason = mb_substr(trim($reason), 0, self::MAX_REASON);
		$entries[] = ['version' => $version, 'skippedBy' => $actorUid, 'skippedAt' => $skippedAt, 'reason' => $reason];
		$this->write($appId, array_slice($entries, -self::MAX));

		$this->auditLogger->record(
			new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_SKIP_VERSION),
			new AuditedVersions(null, $version, null),
			AuditLogger::STATUS_SUCCESS,
			$reason === '' ? null : $reason,
		);

		return true;
	}

	/**
	 * Take a version off the skip list (a no-op, no audit entry, when it is not on it).
	 *
	 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
	 * @return bool True when the version was removed.
	 */
	public function unskip(string $appId, string $version, string $actorUid): bool {
		$entries = $this->entries($appId);
		$kept = array_values(array_filter($entries, static fn (array $entry): bool => $entry['version'] !== $version));
		if (count($kept) === count($entries)) {
			return false;
		}

		$this->write($appId, $kept);
		$this->auditLogger->record(
			new AuditSubject($actorUid, $appId, AuditLogger::OPERATION_UNSKIP_VERSION),
			new AuditedVersions(null, $version, null),
			AuditLogger::STATUS_SUCCESS,
		);

		return true;
	}

	/**
	 * @param list<array{version: string, skippedBy: string, skippedAt: string, reason: string}> $entries
	 */
	private function write(string $appId, array $entries): void {
		$key = self::KEY_PREFIX . $appId;
		if ($entries === []) {
			$this->config->deleteKey(Application::APP_ID, $key);
			return;
		}

		$this->config->setValueString(Application::APP_ID, $key, (string)json_encode($entries));
	}
}
