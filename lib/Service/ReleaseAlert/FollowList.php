<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\ReleaseAlert;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The apps an admin follows the releases of without having them installed,
 * kept in app config under `release_alerts.follow` as a list of
 * `{appId, sourceId, addedBy, addedAt}` (releases-new-release-alerts, design
 * D3). Every change is audited as `settings`.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-an-admin-follows-the-releases-of-an-app-that-is-not-installed
 *
 * @psalm-type FollowEntry = array{appId: string, sourceId: ?string, addedBy: string, addedAt: string}
 */
class FollowList {
	public const KEY = 'release_alerts.follow';

	/** At most this many followed apps, so the sweep stays inside its time budget. */
	public const MAX_ENTRIES = 50;

	/** A Nextcloud app id. */
	private const APP_ID_PATTERN = '/^[a-z0-9_]{1,64}$/';

	/** A source id: `appstore`, or a forge reference such as `github:Owner/repo`. */
	private const SOURCE_ID_PATTERN = '/^[A-Za-z0-9_.:\/-]{1,200}$/';

	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Every followed app, oldest first.
	 *
	 * @return list<FollowEntry>
	 */
	public function all(): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::KEY, ''), true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_filter(array_map(self::entry(...), $decoded)));
	}

	/**
	 * Whether an app is followed.
	 */
	public function isFollowed(string $appId): bool {
		return in_array($appId, array_column($this->all(), 'appId'), true);
	}

	/**
	 * Follow an app, or update the source of one already followed.
	 *
	 * @throws InvalidArgumentException on a malformed app or source id, or a full list
	 * @return FollowEntry
	 */
	public function follow(string $appId, ?string $sourceId, string $uid): array {
		$sourceId = ($sourceId === null || trim($sourceId) === '') ? null : trim($sourceId);
		self::assertIds(trim($appId), $sourceId);
		$appId = trim($appId);

		$entries = array_values(array_filter($this->all(), static fn (array $entry): bool => $entry['appId'] !== $appId));
		if (count($entries) >= self::MAX_ENTRIES) {
			throw new InvalidArgumentException('At most ' . self::MAX_ENTRIES . ' apps can be followed; unfollow one first.');
		}

		$entry = [
			'appId' => $appId,
			'sourceId' => $sourceId,
			'addedBy' => $uid,
			'addedAt' => $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
		];
		$entries[] = $entry;
		$this->write($entries);
		$this->audit($uid, $appId, 'Following releases of ' . $appId . ' from ' . ($sourceId ?? 'its default source'));

		return $entry;
	}

	/**
	 * Stop following an app; false when it was not followed.
	 */
	public function unfollow(string $appId, string $uid): bool {
		$entries = $this->all();
		$kept = array_values(array_filter($entries, static fn (array $entry): bool => $entry['appId'] !== $appId));
		if (count($kept) === count($entries)) {
			return false;
		}

		$this->write($kept);
		$this->audit($uid, $appId, 'Stopped following releases of ' . $appId);

		return true;
	}

	/**
	 * @param list<FollowEntry> $entries
	 */
	private function write(array $entries): void {
		$this->config->setValueString(Application::APP_ID, self::KEY, json_encode($entries, JSON_THROW_ON_ERROR));
	}

	private function audit(string $uid, string $appId, string $message): void {
		$this->auditLogger->record(
			new AuditSubject($uid, $appId, AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
			'Settings changed: ' . $message,
		);
	}

	/**
	 * @throws InvalidArgumentException on a malformed app or source id
	 */
	private static function assertIds(string $appId, ?string $sourceId): void {
		if (preg_match(self::APP_ID_PATTERN, $appId) !== 1) {
			throw new InvalidArgumentException('The app id must be a Nextcloud app id (lowercase letters, digits and underscores).');
		}
		if ($sourceId !== null && preg_match(self::SOURCE_ID_PATTERN, $sourceId) !== 1) {
			throw new InvalidArgumentException('The source id is not valid.');
		}
	}

	/**
	 * A stored entry, or null when it is malformed.
	 *
	 * @return FollowEntry|null
	 */
	private static function entry(mixed $entry): ?array {
		if (!is_array($entry) || !is_string($entry['appId'] ?? null) || $entry['appId'] === '') {
			return null;
		}

		return [
			'appId' => $entry['appId'],
			'sourceId' => is_string($entry['sourceId'] ?? null) ? $entry['sourceId'] : null,
			'addedBy' => (string)($entry['addedBy'] ?? ''),
			'addedAt' => (string)($entry['addedAt'] ?? ''),
		];
	}
}
