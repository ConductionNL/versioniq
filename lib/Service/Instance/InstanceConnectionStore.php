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

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * Keeps the connections to other Versioniq instances in app config, one JSON
 * value per connection under `instance.{id}`, and this instance's own stage
 * under `instance.self` (install-multi-instance-promotion design D1).
 *
 * The app password is encrypted with ICrypto before it is stored and is
 * decrypted only inside withPassword(), for the one call that needs it.
 *
 * @psalm-api
 */
class InstanceConnectionStore {
	public const KEY_PREFIX = 'instance.';
	public const KEY_SELF = 'instance.self';

	/** A connection id: a slug, also the app config key suffix. */
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,62}$/';

	/** The address parts a connection address may not carry. */
	private const REFUSED_ADDRESS_PARTS = ['user' => true, 'pass' => true, 'query' => true, 'fragment' => true];

	private const MAX_LABEL_LENGTH = 80;
	private const MAX_ORDER = 99;

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Every readable connection in chain order; a malformed stored value is left out.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @return list<InstanceConnection>
	 */
	public function all(): array {
		$connections = [];
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			$id = $this->idOfKey($key);
			if ($id === null) {
				continue;
			}

			$connection = $this->get($id);
			if ($connection !== null) {
				$connections[] = $connection;
			}
		}

		usort($connections, static fn (InstanceConnection $a, InstanceConnection $b): int => [$a->order, $a->id] <=> [$b->order, $b->id]);

		return $connections;
	}

	/**
	 * One connection, or null when it does not exist or its stored value is malformed.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	public function get(string $id): ?InstanceConnection {
		if (preg_match(self::ID_PATTERN, $id) !== 1 || $id === 'self') {
			return null;
		}

		$stored = $this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $id, '');
		if ($stored === '') {
			return null;
		}

		return InstanceConnection::fromJson($stored);
	}

	/**
	 * Add a connection, or change one. A new connection needs a password; a
	 * change without one keeps the stored password.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param array<string, mixed> $fields id, name, stage, order, baseUrl, username and password
	 *                                     (the app password, empty to keep the stored one)
	 * @throws InvalidArgumentException naming the first field that is not acceptable
	 */
	public function save(array $fields, string $actorUid): InstanceConnection {
		$id = self::checkedId(self::text($fields['id'] ?? ''));
		$existing = $this->get($id);
		$encrypted = $this->encryptedPassword(password: (string)($fields['password'] ?? ''), existing: $existing);

		$connection = new InstanceConnection([
			'id' => $id,
			'name' => $this->label($fields['name'] ?? '', 'name'),
			'stage' => $this->label($fields['stage'] ?? '', 'stage'),
			'order' => $this->order($fields['order'] ?? 0),
			'baseUrl' => $this->address(self::text($fields['baseUrl'] ?? '')),
			'username' => $this->label($fields['username'] ?? '', 'username'),
			'encryptedPassword' => $encrypted,
			'addedBy' => $existing?->addedBy ?? $actorUid,
			'addedAt' => $existing?->addedAt ?? $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
		]);

		$this->config->setValueString(Application::APP_ID, self::KEY_PREFIX . $id, $connection->toJson(), sensitive: true);

		return $connection;
	}

	/**
	 * A connection id: lowercase letters, digits and hyphens, and never `self`.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @throws InvalidArgumentException when it is not one
	 */
	private static function checkedId(string $id): string {
		if (preg_match(self::ID_PATTERN, $id) !== 1 || $id === 'self') {
			throw new InvalidArgumentException('Use an id of lowercase letters, digits and hyphens, for example acceptance.');
		}

		return $id;
	}

	/**
	 * The password to store: the new one encrypted, or the stored one when a
	 * change leaves it empty. A new connection must name one.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @throws InvalidArgumentException when a new connection has none
	 */
	private function encryptedPassword(
		#[\SensitiveParameter]
		string $password,
		?InstanceConnection $existing,
	): string {
		if ($password !== '') {
			return $this->crypto->encrypt($password);
		}

		if ($existing === null) {
			throw new InvalidArgumentException('Enter the app password of an admin account on that instance.');
		}

		return $existing->encryptedPassword;
	}

	/**
	 * Remove a connection and its cached manifest; the stored password goes with it.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @return bool whether there was a connection to remove
	 */
	public function delete(string $id): bool {
		if ($this->get($id) === null) {
			return false;
		}

		$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $id);
		$this->config->deleteKey(Application::APP_ID, self::KEY_PREFIX . $id . '.manifest');

		return true;
	}

	/**
	 * Run a callback with the decrypted password; it is dropped right after.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @template T
	 * @param callable(string): T $callback
	 * @return T
	 */
	public function withPassword(InstanceConnection $connection, callable $callback): mixed {
		$plaintext = $this->crypto->decrypt($connection->encryptedPassword);
		try {
			return $callback($plaintext);
		} finally {
			$plaintext = null;
			unset($plaintext);
		}
	}

	/**
	 * This instance's own name, stage and place in the chain.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @return array{name: string, stage: string, order: int}
	 */
	public function self(): array {
		/** @var mixed $data */
		$data = json_decode($this->config->getValueString(Application::APP_ID, self::KEY_SELF, ''), true);
		if (!is_array($data)) {
			$data = [];
		}

		/** @var mixed $name */
		$name = $data['name'] ?? '';
		/** @var mixed $stage */
		$stage = $data['stage'] ?? '';
		/** @var mixed $order */
		$order = $data['order'] ?? 0;

		return [
			'name' => is_string($name) ? $name : '',
			'stage' => is_string($stage) ? $stage : '',
			'order' => is_int($order) ? $order : 0,
		];
	}

	/**
	 * Set this instance's own name, stage and place in the chain.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param array<string, mixed> $fields name, stage and order
	 * @return array{name: string, stage: string, order: int}
	 * @throws InvalidArgumentException naming the first field that is not acceptable
	 */
	public function setSelf(array $fields): array {
		$self = [
			'name' => self::text($fields['name'] ?? ''),
			'stage' => $this->label($fields['stage'] ?? '', 'stage'),
			'order' => $this->order($fields['order'] ?? 0),
		];
		if (mb_strlen($self['name']) > self::MAX_LABEL_LENGTH) {
			throw new InvalidArgumentException('Enter a name of at most ' . self::MAX_LABEL_LENGTH . ' characters.');
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_SELF, (string)json_encode($self));

		return $self;
	}

	/**
	 * The connection id an app config key holds, or null for any other key.
	 */
	private function idOfKey(string $key): ?string {
		if (!str_starts_with($key, self::KEY_PREFIX)) {
			return null;
		}

		$id = substr($key, strlen(self::KEY_PREFIX));

		return preg_match(self::ID_PATTERN, $id) === 1 && $id !== 'self' ? $id : null;
	}

	/**
	 * A scalar as trimmed text; anything else is empty.
	 */
	private static function text(mixed $value): string {
		return is_scalar($value) ? trim((string)$value) : '';
	}

	/**
	 * A required, trimmed label of at most 80 characters.
	 *
	 * @throws InvalidArgumentException when it is empty or too long
	 */
	private function label(mixed $value, string $field): string {
		$label = self::text($value);
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
			throw new InvalidArgumentException('Enter a ' . $field . ' of at most ' . self::MAX_LABEL_LENGTH . ' characters.');
		}

		return $label;
	}

	/**
	 * A place in the chain, 0 to 99.
	 *
	 * @throws InvalidArgumentException when it is not a whole number in range
	 */
	private function order(mixed $value): int {
		$value = self::wholeNumber(value: $value);
		if (!is_int($value) || $value < 0 || $value > self::MAX_ORDER) {
			throw new InvalidArgumentException('Enter an order from 0 to ' . self::MAX_ORDER . '.');
		}

		return $value;
	}

	/**
	 * A string of digits as the number it spells; anything else as it is.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	private static function wholeNumber(mixed $value): mixed {
		if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
			return (int)trim($value);
		}

		return $value;
	}

	/**
	 * An https address without a username, password, query or fragment, the
	 * same check as the instance settings' https-only URLs.
	 *
	 * @throws InvalidArgumentException for anything else
	 */
	private function address(string $raw): string {
		$value = rtrim(trim($raw), '/');
		$parts = parse_url($value);
		if ($value === ''
			|| !is_array($parts)
			|| ($parts['scheme'] ?? '') !== 'https'
			|| ($parts['host'] ?? '') === ''
			|| array_intersect_key($parts, self::REFUSED_ADDRESS_PARTS) !== []
		) {
			throw new InvalidArgumentException('Enter an https address without a username, password or query, for example https://acc.example.org.');
		}

		return $value;
	}
}
