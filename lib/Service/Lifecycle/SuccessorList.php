<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Lifecycle;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCA\Versioniq\Service\InstallerService;
use OCP\IAppConfig;

/**
 * Apps that were renamed or replaced, and the successor to offer for them.
 *
 * The shipped entries live in `lib/Settings/successors.json`; an admin's own
 * entries in app config `successors.custom`. Entries are data, never code:
 * Versioniq rewrites no app id anywhere. An entry is offered only when the
 * successor's source lists a version this server can run.
 *
 * @psalm-type Successor = array{from: string, to: string, source: string, custom: bool}
 * @psalm-api
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */
class SuccessorList {
	/** App config key of the admin's own entries (a JSON list). */
	public const KEY_CUSTOM = 'successors.custom';

	/** The shipped entries. */
	public const SHIPPED_FILE = __DIR__ . '/../../Settings/successors.json';

	public function __construct(
		private IAppConfig $config,
		private InstallerService $installer,
	) {
	}

	/**
	 * Every entry: the shipped ones, then the admin's.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 * @return list<Successor>
	 */
	public function entries(): array {
		return [...self::shipped(), ...$this->custom()];
	}

	/**
	 * Adds an admin entry.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 * @return Successor
	 * @throws InvalidArgumentException For a malformed entry, an app that would succeed itself, or one already listed.
	 */
	public function add(string $from, string $to, string $source): array {
		$entry = self::validEntry(trim($from), trim($to), trim($source));
		foreach ($this->entries() as $existing) {
			if ($existing['from'] === $entry['from'] && $existing['to'] === $entry['to']) {
				throw new InvalidArgumentException('This successor is already listed.');
			}
		}
		$this->store([...$this->custom(), $entry]);

		return $entry;
	}

	/**
	 * Removes an admin entry; a shipped entry cannot be removed.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 * @return bool Whether an entry was removed.
	 */
	public function remove(string $from, string $to): bool {
		$kept = array_values(array_filter($this->custom(), static fn (array $entry): bool => $entry['from'] !== $from || $entry['to'] !== $to));
		if (count($kept) === count($this->custom())) {
			return false;
		}
		$this->store($kept);

		return true;
	}

	/**
	 * The successor to offer for an app, with the newest version of it this
	 * server can run, or null when no listed successor has one.
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 * @return array{from: string, to: string, source: string, custom: bool, version: string}|null
	 */
	public function offerFor(string $appId): ?array {
		foreach ($this->entries() as $entry) {
			if ($entry['from'] !== $appId) {
				continue;
			}
			$version = $this->newestRunnable($entry['to'], $entry['source']);
			if ($version !== null) {
				return $entry + ['version' => $version];
			}
		}

		return null;
	}

	/**
	 * The newest version the successor's source lists as compatible with this server.
	 */
	private function newestRunnable(string $appId, string $source): ?string {
		try {
			$listing = $this->installer->getAppVersions($appId, $source);
		} catch (\Throwable) {
			return null;
		}
		if (($listing['hasError'] ?? false) === true || !is_array($listing['availableVersions'] ?? null)) {
			return null;
		}

		$newest = null;
		/** @var mixed $version */
		foreach ($listing['availableVersions'] as $version) {
			$candidate = is_array($version) && ($version['serverCompatible'] ?? null) === true ? ($version['version'] ?? null) : null;
			if (is_string($candidate) && ($newest === null || version_compare($candidate, $newest, '>'))) {
				$newest = $candidate;
			}
		}

		return $newest;
	}

	/**
	 * @return list<Successor>
	 */
	private static function shipped(): array {
		$raw = @file_get_contents(self::SHIPPED_FILE);

		return self::entriesFrom(is_string($raw) ? $raw : '', false, 'successors');
	}

	/**
	 * @return list<Successor>
	 */
	private function custom(): array {
		return self::entriesFrom($this->config->getValueString(Application::APP_ID, self::KEY_CUSTOM, ''), true, null);
	}

	/**
	 * @param list<Successor> $entries
	 */
	private function store(array $entries): void {
		$plain = array_map(static fn (array $entry): array => ['from' => $entry['from'], 'to' => $entry['to'], 'source' => $entry['source']], $entries);
		$this->config->setValueString(Application::APP_ID, self::KEY_CUSTOM, json_encode($plain, JSON_THROW_ON_ERROR));
	}

	/**
	 * The well-formed entries of a JSON document, under `$key` or at its root.
	 *
	 * @return list<Successor>
	 */
	private static function entriesFrom(string $raw, bool $custom, ?string $key): array {
		try {
			/** @var mixed $decoded */
			$decoded = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		/** @var mixed $list */
		$list = $key !== null && is_array($decoded) ? ($decoded[$key] ?? []) : $decoded;

		$entries = [];
		/** @var mixed $item */
		foreach (is_array($list) ? $list : [] as $item) {
			$entry = self::entryOf($item, $custom);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * One stored entry, or null when it is not well formed.
	 *
	 * @return Successor|null
	 */
	private static function entryOf(mixed $item, bool $custom): ?array {
		if (!is_array($item) || !is_string($item['from'] ?? null) || !is_string($item['to'] ?? null) || !is_string($item['source'] ?? null)) {
			return null;
		}
		try {
			$entry = self::validEntry($item['from'], $item['to'], $item['source']);
			$entry['custom'] = $custom;

			return $entry;
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * A checked entry.
	 *
	 * @return Successor
	 * @throws InvalidArgumentException
	 */
	private static function validEntry(string $from, string $to, string $source): array {
		if (!AppIdFormat::isValid($from) || !AppIdFormat::isValid($to)) {
			throw new InvalidArgumentException('Both apps must be app ids.');
		}
		if ($from === $to) {
			throw new InvalidArgumentException('An app cannot be its own successor.');
		}
		if ($source !== 'appstore' && preg_match('#^[a-z]+:[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $source) !== 1) {
			throw new InvalidArgumentException('The source must be appstore or <forge>:<owner>/<repo>.');
		}

		return ['from' => $from, 'to' => $to, 'source' => $source, 'custom' => true];
	}
}
