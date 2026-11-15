<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Release;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Db\AuditEntry;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Known issues an admin records against a version (releases-version-facts
 * D4): `known_issues.{appId}` holds a JSON map from version to a list of
 * `{text, workaround, link, addedBy, addedAt}`, at most 20 entries per app
 * with the oldest dropped first. A link must be https. Every change is
 * audited as `settings`.
 *
 * @psalm-type KnownIssue = array{text: string, workaround: string, link: ?string, addedBy: string, addedAt: string}
 */
class KnownIssueStore {
	public const KEY_PREFIX = 'known_issues.';

	public const MAX_PER_APP = 20;

	private const MAX_TEXT = 500;

	public function __construct(
		private IAppConfig $config,
		private AuditLogger $audit,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Every admin note of an app, per version.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 * @return array<string, list<KnownIssue>>
	 */
	public function forApp(string $appId): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, ''), true);
		if (!is_array($decoded)) {
			return [];
		}

		$notes = [];
		foreach ($decoded as $version => $entries) {
			if (is_array($entries)) {
				$notes[(string)$version] = array_values(array_filter($entries, static fn (mixed $entry): bool => is_array($entry) && is_string($entry['text'] ?? null)));
			}
		}

		/** @var array<string, list<KnownIssue>> $notes */
		return array_filter($notes);
	}

	/**
	 * Record a known issue against a version.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 * @param array{appId: string, version: string, actor: string} $target The app, the version and the admin.
	 * @param array<string, mixed> $issue `text`, and optionally `workaround` and an https `link`.
	 * @return list<KnownIssue> The version's notes after the change.
	 * @throws InvalidArgumentException When the text is missing or too long, or the link is not https.
	 */
	public function add(array $target, array $issue): array {
		$entry = self::validated($issue) + [
			'addedBy' => $target['actor'],
			'addedAt' => $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(DATE_ATOM),
		];

		$notes = $this->forApp($target['appId']);
		$notes[$target['version']][] = $entry;
		$this->save($target['appId'], self::capped($notes));
		$this->record($target, 'Known issue added: ' . $entry['text']);

		return $this->forApp($target['appId'])[$target['version']] ?? [];
	}

	/**
	 * Remove one known issue of a version by its position.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
	 * @param array{appId: string, version: string, actor: string} $target The app, the version and the admin.
	 * @return list<KnownIssue> The version's notes after the change.
	 * @throws InvalidArgumentException When the version has no note at that position.
	 */
	public function remove(array $target, int $index): array {
		$notes = $this->forApp($target['appId']);
		$entries = $notes[$target['version']] ?? [];
		if (!isset($entries[$index])) {
			throw new InvalidArgumentException('This version has no known issue at that position.');
		}

		$removed = $entries[$index];
		array_splice($entries, $index, 1);
		$notes[$target['version']] = $entries;
		$this->save($target['appId'], array_filter($notes));
		$this->record($target, 'Known issue removed: ' . $removed['text']);

		return $entries;
	}

	/**
	 * The issue's fields, trimmed and checked.
	 *
	 * @param array<string, mixed> $issue
	 * @return array{text: string, workaround: string, link: ?string}
	 */
	private static function validated(array $issue): array {
		$text = is_string($issue['text'] ?? null) ? trim($issue['text']) : '';
		$workaround = is_string($issue['workaround'] ?? null) ? trim($issue['workaround']) : '';
		if ($text === '' || mb_strlen($text) > self::MAX_TEXT || mb_strlen($workaround) > self::MAX_TEXT) {
			throw new InvalidArgumentException('A known issue needs a text, and text and workaround are at most 500 characters.');
		}

		$link = is_string($issue['link'] ?? null) ? trim($issue['link']) : '';
		if ($link !== '' && (filter_var($link, FILTER_VALIDATE_URL) === false || strtolower((string)parse_url($link, PHP_URL_SCHEME)) !== 'https')) {
			throw new InvalidArgumentException('The link of a known issue must be an https address.');
		}

		return ['text' => $text, 'workaround' => $workaround, 'link' => $link === '' ? null : $link];
	}

	/**
	 * The notes with the oldest dropped until at most MAX_PER_APP remain.
	 *
	 * @param array<string, list<KnownIssue>> $notes
	 * @return array<string, list<KnownIssue>>
	 */
	private static function capped(array $notes): array {
		$all = [];
		foreach ($notes as $version => $entries) {
			foreach ($entries as $position => $entry) {
				$all[] = ['version' => (string)$version, 'position' => $position, 'addedAt' => $entry['addedAt']];
			}
		}
		usort($all, static fn (array $a, array $b): int => strcmp($a['addedAt'], $b['addedAt']));

		foreach (array_slice($all, 0, max(0, count($all) - self::MAX_PER_APP)) as $oldest) {
			unset($notes[$oldest['version']][$oldest['position']]);
		}

		return array_filter(array_map('array_values', $notes));
	}

	/**
	 * @param array<string, list<KnownIssue>> $notes
	 */
	private function save(string $appId, array $notes): void {
		$this->config->setValueString(Application::APP_ID, self::KEY_PREFIX . $appId, (string)json_encode($notes, JSON_UNESCAPED_SLASHES));
	}

	/**
	 * @param array{appId: string, version: string, actor: string} $target
	 */
	private function record(array $target, string $message): void {
		$this->audit->record(
			new AuditSubject($target['actor'], $target['appId'], AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions(null, $target['version'], null),
			AuditEntry::STATUS_SUCCESS,
			$message
		);
	}
}
