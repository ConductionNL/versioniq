<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Instance;

/**
 * One connection to another Versioniq instance (install-multi-instance-promotion
 * design D1): an admin account's app password, kept encrypted, used only to
 * read that instance's manifest.
 *
 * The encrypted password never leaves this object through toPublicArray().
 *
 * @psalm-api
 */
final class InstanceConnection {
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $stage,
		public readonly int $order,
		public readonly string $baseUrl,
		public readonly string $username,
		public readonly string $encryptedPassword,
		public readonly string $addedBy,
		public readonly string $addedAt,
	) {
	}

	/**
	 * A connection read back from its stored JSON, or null when the value is
	 * malformed or misses a field, so a damaged entry reads as absent.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	public static function fromJson(string $json): ?self {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return null;
		}

		$text = [];
		foreach (['id', 'name', 'stage', 'baseUrl', 'username', 'encryptedPassword', 'addedBy', 'addedAt'] as $field) {
			$value = $data[$field] ?? null;
			if (!is_string($value)) {
				return null;
			}
			$text[$field] = $value;
		}

		$order = $data['order'] ?? null;
		if (!is_int($order) || $text['id'] === '' || $text['encryptedPassword'] === '') {
			return null;
		}

		return new self(
			$text['id'],
			$text['name'],
			$text['stage'],
			$order,
			$text['baseUrl'],
			$text['username'],
			$text['encryptedPassword'],
			$text['addedBy'],
			$text['addedAt'],
		);
	}

	/**
	 * The stored form, encrypted password included.
	 */
	public function toJson(): string {
		return (string)json_encode([
			'id' => $this->id,
			'name' => $this->name,
			'stage' => $this->stage,
			'order' => $this->order,
			'baseUrl' => $this->baseUrl,
			'username' => $this->username,
			'encryptedPassword' => $this->encryptedPassword,
			'addedBy' => $this->addedBy,
			'addedAt' => $this->addedAt,
		], JSON_UNESCAPED_SLASHES);
	}

	/**
	 * What an endpoint may return: everything but the password.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @return array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string}
	 */
	public function toPublicArray(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'stage' => $this->stage,
			'order' => $this->order,
			'baseUrl' => $this->baseUrl,
			'username' => $this->username,
			'addedBy' => $this->addedBy,
			'addedAt' => $this->addedAt,
		];
	}
}
