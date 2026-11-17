<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * Applies the update groups to a planned run (autoupdate-run-batching D3).
 *
 * The members of a group are placed together, in the group's stored order,
 * where the group's first planned member stood. A group moves only when no
 * member is pinned, no member's source failed and no member's candidate
 * failed before; otherwise every member takes the reason of the first member
 * that held it, so nothing in the group installs. Members with no candidate
 * do not hold a group: they are already current. Apps without a policy are
 * not planned, so they never install and never hold their group.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdateGroups {
	/** The reasons that hold a whole group. */
	private const HOLDING_REASONS = [
		AutoUpdateMember::REASON_PINNED,
		AutoUpdateMember::REASON_SOURCE_ERROR,
		AutoUpdateMember::REASON_BLOCKED,
	];

	/**
	 * The planned places with every group's members placed together and
	 * tagged with the group; a group runs first when any member does.
	 *
	 * @param list<array{member: AutoUpdateMember, first: bool}> $planned
	 * @param list<UpdateGroup> $groups
	 *
	 * @return list<array{member: AutoUpdateMember, first: bool}>
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public static function apply(array $planned, array $groups): array {
		$byApp = array_column(
			array_map(static fn (array $place): array => ['key' => $place['member']->appId, 'place' => $place], $planned),
			'place',
			'key'
		);
		$groupOf = self::groupOf($groups);

		$result = [];
		$placed = [];
		foreach ($planned as $place) {
			$group = $groupOf[$place['member']->appId] ?? null;
			if ($group === null) {
				$result[] = $place;
			} elseif (!isset($placed[$group->name])) {
				$placed[$group->name] = true;
				array_push($result, ...self::groupPlaces($group, $byApp));
			}
		}

		return $result;
	}

	/**
	 * The group each app is in; an app listed twice stays with the first.
	 *
	 * @param list<UpdateGroup> $groups
	 *
	 * @return array<string, UpdateGroup>
	 */
	private static function groupOf(array $groups): array {
		$groupOf = [];
		foreach ($groups as $group) {
			foreach ($group->apps as $appId) {
				$groupOf[$appId] ??= $group;
			}
		}

		return $groupOf;
	}

	/**
	 * The group's planned members in its stored order, held together when
	 * one of them holds.
	 *
	 * @param array<string, array{member: AutoUpdateMember, first: bool}> $byApp
	 *
	 * @return list<array{member: AutoUpdateMember, first: bool}>
	 */
	private static function groupPlaces(UpdateGroup $group, array $byApp): array {
		$members = [];
		$first = false;
		foreach ($group->apps as $appId) {
			if (isset($byApp[$appId])) {
				$members[] = $byApp[$appId]['member'];
				$first = $first || $byApp[$appId]['first'];
			}
		}

		$hold = self::holdingReason($members);

		return array_map(
			static fn (AutoUpdateMember $member): array => [
				'member' => ($hold === null ? $member : $member->withReason($hold))->inGroup($group->name),
				'first' => $first,
			],
			$members
		);
	}

	/**
	 * The reason of the first member, in group order, that holds the group,
	 * or null when the group may move.
	 *
	 * @param list<AutoUpdateMember> $members
	 */
	private static function holdingReason(array $members): ?string {
		foreach ($members as $member) {
			if (in_array($member->reason, self::HOLDING_REASONS, true)) {
				return $member->reason;
			}
		}

		return null;
	}
}
