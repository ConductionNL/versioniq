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

use InvalidArgumentException;

/**
 * An update group (autoupdate-run-batching D2): apps that update together, in
 * the listed order. A group holds 2 to 20 distinct app ids.
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class UpdateGroup {
	public const MIN_APPS = 2;
	public const MAX_APPS = 20;

	/**
	 * @param string       $id    Stable id of the group.
	 * @param string       $name  The name an admin sees.
	 * @param list<string> $apps  Member app ids, in install order.
	 * @param string       $setBy The admin who last saved the group.
	 * @param string       $setAt When it was last saved, ISO-8601.
	 *
	 * @throws InvalidArgumentException when a field is empty or the app list is invalid
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $apps,
		public readonly string $setBy,
		public readonly string $setAt,
	) {
		if ($id === '' || $name === '' || $setBy === '' || $setAt === '') {
			throw new InvalidArgumentException('Update group requires a non-empty id, name, setBy and setAt');
		}
		$count = count($apps);
		if ($count < self::MIN_APPS || $count > self::MAX_APPS) {
			throw new InvalidArgumentException('Update group must hold 2 to 20 apps');
		}
		if (in_array('', $apps, true) || count(array_unique($apps)) !== $count) {
			throw new InvalidArgumentException('Update group apps must be distinct, non-empty app ids');
		}
	}

	/**
	 * @return array{id: string, name: string, apps: list<string>, setBy: string, setAt: string}
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'apps' => $this->apps,
			'setBy' => $this->setBy,
			'setAt' => $this->setAt,
		];
	}

	/**
	 * @param array<array-key, mixed> $payload
	 *
	 * @throws InvalidArgumentException when the payload is not a valid group
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public static function fromArray(array $payload): self {
		$apps = $payload['apps'] ?? null;
		foreach (['id', 'name', 'setBy', 'setAt'] as $field) {
			if (!is_string($payload[$field] ?? null)) {
				throw new InvalidArgumentException('Update group payload missing required string fields');
			}
		}
		if (!is_array($apps) || !array_is_list($apps)) {
			throw new InvalidArgumentException('Update group apps must be a list');
		}
		$names = [];
		foreach ($apps as $app) {
			if (!is_string($app)) {
				throw new InvalidArgumentException('Update group apps must be strings');
			}
			$names[] = $app;
		}

		return new self(
			(string)$payload['id'],
			(string)$payload['name'],
			$names,
			(string)$payload['setBy'],
			(string)$payload['setAt'],
		);
	}
}
