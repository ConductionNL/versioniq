<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use InvalidArgumentException;

/**
 * Reads the update groups an admin sends to `PUT /api/auto-update/groups`
 * (autoupdate-run-batching D2). The whole list is checked before anything is
 * stored: a group of one, a duplicate name, an app in two groups or an app
 * this instance cannot manage refuses the whole request.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class UpdateGroupInput {
	/**
	 * The groups in the request, each saved by the admin at the given time.
	 * A group keeps the id it was sent with; a new one gets a fresh id.
	 *
	 * @param mixed                          $raw          The request's `groups`.
	 * @param callable(string): bool         $isManageable Whether this instance can manage the app.
	 * @param array{by: string, at: string}  $saved        The admin and the time (ISO-8601).
	 *
	 * @return list<UpdateGroup>
	 *
	 * @throws InvalidArgumentException with a message for the admin when the list is refused
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public static function parse(mixed $raw, callable $isManageable, array $saved): array {
		if (!is_array($raw) || !array_is_list($raw)) {
			throw new InvalidArgumentException('groups must be a list.');
		}

		$groups = [];
		$names = [];
		$owner = [];
		foreach ($raw as $entry) {
			$group = self::group($entry, $saved);
			$key = mb_strtolower($group->name);
			if (isset($names[$key])) {
				throw new InvalidArgumentException(sprintf('The group name "%s" is used twice.', $group->name));
			}
			$names[$key] = true;

			foreach ($group->apps as $appId) {
				if (isset($owner[$appId])) {
					throw new InvalidArgumentException(sprintf('%s is in two groups: "%s" and "%s".', $appId, $owner[$appId], $group->name));
				}
				if (!$isManageable($appId)) {
					throw new InvalidArgumentException(sprintf('%s cannot be updated automatically on this instance.', $appId));
				}
				$owner[$appId] = $group->name;
			}

			$groups[] = $group;
		}

		return $groups;
	}

	/**
	 * One group from the request.
	 *
	 * @param array{by: string, at: string} $saved
	 *
	 * @throws InvalidArgumentException when the entry is not a valid group
	 */
	private static function group(mixed $entry, array $saved): UpdateGroup {
		$name = is_array($entry) && is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
		if ($name === '') {
			throw new InvalidArgumentException('Every group needs a name.');
		}

		$apps = is_array($entry['apps'] ?? null) ? array_values($entry['apps']) : null;
		if ($apps === null || array_filter($apps, static fn (mixed $app): bool => !is_string($app)) !== []) {
			throw new InvalidArgumentException(sprintf('The apps of group "%s" must be a list of app ids.', $name));
		}

		$id = is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : bin2hex(random_bytes(8));
		try {
			/** @var list<string> $apps */
			return new UpdateGroup($id, $name, $apps, $saved);
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException(sprintf(
				'Group "%s" must hold %d to %d different apps.',
				$name,
				UpdateGroup::MIN_APPS,
				UpdateGroup::MAX_APPS
			));
		}
	}
}
