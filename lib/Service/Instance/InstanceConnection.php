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
	/**
	 * The text fields of the stored form, in their stored order around `order`.
	 */
	private const TEXT_FIELDS = ['id', 'name', 'stage', 'baseUrl', 'username', 'encryptedPassword', 'addedBy', 'addedAt'];

	public readonly string $id;
	public readonly string $name;
	public readonly string $stage;
	public readonly int $order;
	public readonly string $baseUrl;
	public readonly string $username;
	public readonly string $encryptedPassword;
	public readonly string $addedBy;
	public readonly string $addedAt;

	/**
	 * A connection from its fields.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, encryptedPassword: string, addedBy: string, addedAt: string} $fields
	 */
	public function __construct(array $fields) {
		$this->id = $fields['id'];
		$this->name = $fields['name'];
		$this->stage = $fields['stage'];
		$this->order = $fields['order'];
		$this->baseUrl = $fields['baseUrl'];
		$this->username = $fields['username'];
		$this->encryptedPassword = $fields['encryptedPassword'];
		$this->addedBy = $fields['addedBy'];
		$this->addedAt = $fields['addedAt'];
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

		$text = self::textFields($data);
		$order = $data['order'] ?? null;
		if ($text === null || !is_int($order)) {
			return null;
		}

		return new self(['order' => $order] + $text);
	}

	/**
	 * The text fields of a decoded value, or null when one is missing or not text.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param array<array-key, mixed> $data The decoded JSON.
	 * @return array<string, string>|null
	 */
	private static function textFields(array $data): ?array {
		$text = [];
		foreach (self::TEXT_FIELDS as $field) {
			if (!is_string($data[$field] ?? null)) {
				return null;
			}
			$text[$field] = $data[$field];
		}

		return self::filled(text: $text) ? $text : null;
	}

	/**
	 * Whether the fields a connection cannot do without are not empty.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param array<string, string> $text The text fields.
	 */
	private static function filled(array $text): bool {
		return $text['id'] !== '' && $text['encryptedPassword'] !== '';
	}

	/**
	 * The stored form, encrypted password included.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	public function toJson(): string {
		return (string)json_encode($this->fields(), JSON_UNESCAPED_SLASHES);
	}

	/**
	 * What an endpoint may return: everything but the password.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @return array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string}
	 */
	public function toPublicArray(): array {
		$fields = $this->fields();
		unset($fields['encryptedPassword']);

		return $fields;
	}

	/**
	 * Every field, in the stored order.
	 *
	 * @return array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, encryptedPassword: string, addedBy: string, addedAt: string}
	 */
	private function fields(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'stage' => $this->stage,
			'order' => $this->order,
			'baseUrl' => $this->baseUrl,
			'username' => $this->username,
			'encryptedPassword' => $this->encryptedPassword,
			'addedBy' => $this->addedBy,
			'addedAt' => $this->addedAt,
		];
	}
}
