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

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the update groups under app config key
 * `auto_update.groups` (autoupdate-run-batching D2). A missing or malformed
 * value reads as no groups, and an invalid entry is skipped; both are logged,
 * never fatal — the same handling as {@see \OCA\Versioniq\Service\Policy\PolicyStore}.
 *
 * Wired into the planner and the API by tasks 2.2 and 2.4.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 * @psalm-api
 */
class UpdateGroupStore {
	public const CONFIG_KEY = 'auto_update.groups';
	private const JSON_DEPTH = 8;

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every stored group, in stored order.
	 *
	 * @return list<UpdateGroup>
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function all(): array {
		$decoded = $this->decoded();
		$groups = [];
		foreach ($decoded as $entry) {
			try {
				$groups[] = UpdateGroup::fromArray(is_array($entry) ? $entry : []);
			} catch (\InvalidArgumentException $error) {
				$this->logger->warning('UpdateGroupStore: invalid group skipped', [
					'message' => $error->getMessage(),
				]);
			}
		}

		return $groups;
	}

	/**
	 * The stored list, or an empty one when nothing is stored or it is not a JSON list.
	 *
	 * @return list<mixed>
	 */
	private function decoded(): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		$decoded = $raw === '' ? [] : $this->parsed($raw);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			$this->logger->warning('UpdateGroupStore: groups value is not a list, treating as no groups');

			return [];
		}

		return $decoded;
	}

	/**
	 * The stored JSON decoded, or an empty list when it does not parse.
	 */
	private function parsed(string $raw): mixed {
		try {
			$decoded = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('UpdateGroupStore: malformed groups JSON, treating as no groups', [
				'message' => $error->getMessage(),
			]);

			return [];
		}

		return $decoded;
	}

	/**
	 * Replaces the whole list of groups.
	 *
	 * @param list<UpdateGroup> $groups
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function replaceAll(array $groups): void {
		$this->config->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode(array_map(static fn (UpdateGroup $group): array => $group->toArray(), $groups), JSON_THROW_ON_ERROR)
		);
	}
}
