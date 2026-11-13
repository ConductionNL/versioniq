<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Audit;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * What Versioniq last saw of every app (its version and whether it was
 * enabled) and of the server, so a change made outside Versioniq can be told
 * apart from one it made itself.
 *
 * The record starts with a baseline the first hourly check writes. Until
 * then, single-app updates are not stored: a partial record would make every
 * other app look new on the first full comparison and flood the history.
 *
 * It also remembers which apps were left disabled by an update, for the
 * notice on their card.
 *
 * @psalm-type SeenApp = array{version: string, enabled: bool}
 * @psalm-type LeftDisabled = array{toVersion: string, compatible: bool, compatibleVersion: ?string}
 * @psalm-type SeenState = array{serverVersion: string, apps: array<string, SeenApp>, disabledByUpdate: array<string, LeftDisabled>}
 *
 * @psalm-api
 */
class SeenStateStore {
	public const KEY = 'external.seen';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The stored record, or null when no baseline was written yet or the
	 * stored value is unreadable (then the next hourly check writes a new one).
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @return SeenState|null
	 */
	public function read(): ?array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return null;
		}
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($decoded)) {
			return null;
		}
		/** @var mixed $serverVersion */
		$serverVersion = $decoded['serverVersion'] ?? null;
		/** @var mixed $apps */
		$apps = $decoded['apps'] ?? null;
		/** @var mixed $leftDisabled */
		$leftDisabled = $decoded['disabledByUpdate'] ?? null;
		if (!is_string($serverVersion) || !is_array($apps)) {
			return null;
		}

		return [
			'serverVersion' => $serverVersion,
			'apps' => self::apps($apps),
			'disabledByUpdate' => self::leftDisabled(is_array($leftDisabled) ? $leftDisabled : []),
		];
	}

	/**
	 * Whether a baseline exists.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function hasBaseline(): bool {
		return $this->read() !== null;
	}

	/**
	 * Replaces the whole record.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @param SeenState $state
	 */
	public function write(array $state): void {
		$this->config->setValueString(Application::APP_ID, self::KEY, json_encode([
			'serverVersion' => $state['serverVersion'],
			'apps' => (object)$state['apps'],
			'disabledByUpdate' => (object)$state['disabledByUpdate'],
		], JSON_THROW_ON_ERROR));
	}

	/**
	 * What was last seen of one app, or null.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @return SeenApp|null
	 */
	public function app(string $appId): ?array {
		return $this->read()['apps'][$appId] ?? null;
	}

	/**
	 * Stores what is now seen of one app. Does nothing before the baseline.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function rememberApp(string $appId, string $version, bool $enabled): void {
		$state = $this->read();
		if ($state === null) {
			return;
		}
		$state['apps'][$appId] = ['version' => $version, 'enabled' => $enabled];
		if ($enabled) {
			unset($state['disabledByUpdate'][$appId]);
		}
		$this->write($state);
	}

	/**
	 * Remembers that an update left the app disabled, for its card.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @param LeftDisabled $leftDisabled
	 */
	public function markDisabledByUpdate(string $appId, array $leftDisabled): void {
		$state = $this->read();
		if ($state === null) {
			return;
		}
		$state['disabledByUpdate'][$appId] = $leftDisabled;
		$this->write($state);
	}

	/**
	 * The apps an update left disabled, by app id.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @return array<string, LeftDisabled>
	 */
	public function disabledByUpdate(): array {
		return $this->read()['disabledByUpdate'] ?? [];
	}

	/**
	 * @param array<array-key, mixed> $raw
	 * @return array<string, SeenApp>
	 */
	private static function apps(array $raw): array {
		$apps = [];
		/** @var array<array-key, mixed> $entry */
		foreach (array_filter($raw, is_array(...)) as $appId => $entry) {
			/** @var mixed $version */
			$version = $entry['version'] ?? null;
			/** @var mixed $enabled */
			$enabled = $entry['enabled'] ?? null;
			if (is_string($appId) && is_string($version) && is_bool($enabled)) {
				$apps[$appId] = ['version' => $version, 'enabled' => $enabled];
			}
		}

		return $apps;
	}

	/**
	 * @param array<array-key, mixed> $raw
	 * @return array<string, LeftDisabled>
	 */
	private static function leftDisabled(array $raw): array {
		$left = [];
		/** @var array<array-key, mixed> $entry */
		foreach (array_filter($raw, is_array(...)) as $appId => $entry) {
			/** @var mixed $toVersion */
			$toVersion = $entry['toVersion'] ?? null;
			/** @var mixed $compatibleVersion */
			$compatibleVersion = $entry['compatibleVersion'] ?? null;
			if (is_string($appId) && is_string($toVersion)) {
				$left[$appId] = [
					'toVersion' => $toVersion,
					'compatible' => ($entry['compatible'] ?? false) === true,
					'compatibleVersion' => is_string($compatibleVersion) ? $compatibleVersion : null,
				];
			}
		}

		return $left;
	}
}
