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

	/** The admin who last saved the group. */
	public readonly string $setBy;

	/** When it was last saved, ISO-8601. */
	public readonly string $setAt;

	/**
	 * @param string                       $id    Stable id of the group.
	 * @param string                       $name  The name an admin sees.
	 * @param list<string>                 $apps  Member app ids, in install order.
	 * @param array{by: string, at: string} $saved The admin who last saved the group, and when (ISO-8601).
	 *
	 * @throws InvalidArgumentException when a field is empty or the app list is invalid
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $apps,
		array $saved,
	) {
		$this->setBy = (string)($saved['by'] ?? '');
		$this->setAt = (string)($saved['at'] ?? '');
		if (in_array('', [$id, $name, $this->setBy, $this->setAt], true)) {
			throw new InvalidArgumentException('Update group requires a non-empty id, name, setBy and setAt');
		}
		self::assertValidApps($apps);
	}

	/**
	 * @param list<string> $apps Member app ids.
	 *
	 * @throws InvalidArgumentException when the list is too short, too long, or holds an empty or repeated id
	 */
	private static function assertValidApps(array $apps): void {
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
		foreach (['id', 'name', 'setBy', 'setAt'] as $field) {
			if (!is_string($payload[$field] ?? null)) {
				throw new InvalidArgumentException('Update group payload missing required string fields');
			}
		}

		return new self(
			(string)$payload['id'],
			(string)$payload['name'],
			self::appNames($payload['apps'] ?? null),
			['by' => (string)$payload['setBy'], 'at' => (string)$payload['setAt']],
		);
	}

	/**
	 * The payload's app list as strings.
	 *
	 * @param mixed $apps The payload's `apps`.
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException when it is not a list of strings
	 */
	private static function appNames(mixed $apps): array {
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

		return $names;
	}
}
