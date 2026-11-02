<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Settings;

use OCA\Versioniq\AppInfo\Application;

/**
 * One JSON record per app id in app config, under the key `KEY_PREFIX . appId`.
 * A missing value reads as null; malformed JSON, or a payload hydrate() rejects,
 * is logged as a warning and reads as null.
 *
 * The using class has the properties `$config` (IAppConfig) and `$logger`
 * (LoggerInterface), the constants KEY_PREFIX, JSON_DEPTH, MALFORMED_LOG and
 * INVALID_LOG, and `hydrate(array $payload): T`, which throws
 * InvalidArgumentException for a payload that is not a valid record.
 *
 * @template T of object
 */
trait AppConfigJsonRecords {
	/**
	 * @return T|null
	 */
	private function readRecord(string $appId): ?object {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key($appId), '');
		if ($raw === '') {
			return null;
		}

		return $this->decode($appId, $raw);
	}

	/**
	 * Every stored record, keyed by app id; entries that are not a valid record are left out.
	 *
	 * @return array<string, T>
	 */
	private function readAllRecords(): array {
		$records = [];
		foreach ($this->config->getAllValues(Application::APP_ID, self::KEY_PREFIX) as $key => $value) {
			$entry = $this->recordEntry($key, $value);
			if ($entry === null) {
				continue;
			}
			$record = $this->decode($entry[0], $entry[1]);
			if ($record !== null) {
				$records[$entry[0]] = $record;
			}
		}

		return $records;
	}

	/**
	 * The app id and raw JSON of one app config entry, or null when the value
	 * is empty or not a string, or the key has no app id under the prefix.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function recordEntry(string $key, mixed $value): ?array {
		if (!is_string($value) || $value === '') {
			return null;
		}
		$appId = $this->appIdFromKey($key);

		return $appId === null ? null : [$appId, $value];
	}

	/**
	 * The app id an app config key stores a record for, or null when the key
	 * is outside the prefix or names no app.
	 */
	private function appIdFromKey(string $key): ?string {
		if (!str_starts_with($key, self::KEY_PREFIX)) {
			return null;
		}
		$appId = substr($key, strlen(self::KEY_PREFIX));

		return $appId === '' ? null : $appId;
	}

	/**
	 * @param array<array-key, mixed> $payload
	 */
	private function writeRecord(string $appId, array $payload): void {
		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($payload, JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * @return T|null
	 */
	private function decode(string $appId, string $raw): ?object {
		$payload = $this->decodeJson($appId, $raw);

		return $payload === null ? null : $this->hydrateOrWarn($appId, $payload);
	}

	/**
	 * The stored JSON as an array, or null when it is malformed (logged) or not an array.
	 *
	 * @return array<array-key, mixed>|null
	 */
	private function decodeJson(string $appId, string $raw): ?array {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning(self::MALFORMED_LOG, $this->logContext($appId, $error));

			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @return T|null
	 */
	private function hydrateOrWarn(string $appId, array $payload): ?object {
		try {
			return $this->hydrate($payload);
		} catch (\InvalidArgumentException $error) {
			$this->logger->warning(self::INVALID_LOG, $this->logContext($appId, $error));

			return null;
		}
	}

	/**
	 * @return array{appId: string, message: string}
	 */
	private function logContext(string $appId, \Throwable $error): array {
		return [
			'appId' => $appId,
			'message' => $error->getMessage(),
		];
	}

	private function key(string $appId): string {
		return self::KEY_PREFIX . $appId;
	}
}
