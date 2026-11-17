<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Policy;

use OCA\Versioniq\Service\AutoUpdate\AutoUpdateWindow;
use OCA\Versioniq\Service\Installer\AppIdFormat;

/**
 * The policy file of design D3: read, validate and write it.
 *
 *     {
 *       "schemaVersion": 1,
 *       "autoUpdate": { "enabled": true, "window": "01:00-05:00", "defaultLevel": "patch" },
 *       "policies": { "openregister": "minor", "calendar": "none" }
 *     }
 *
 * Only these keys are known. `autoUpdate` and each of its keys may be left
 * out; `policies` may not. Validation reports every problem at once, each
 * with its JSON path, so one run of the import shows all there is to fix.
 *
 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
 * @psalm-api
 */
final class PolicyFile {
	public const SCHEMA_VERSION = 1;

	private const TOP_KEYS = ['schemaVersion', 'autoUpdate', 'policies'];
	private const AUTO_UPDATE_KEYS = ['enabled', 'window', 'defaultLevel'];
	private const JSON_DEPTH = 8;

	/**
	 * Reads and validates a policy file.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @throws PolicyFileException When the file cannot be read or is not a valid policy file.
	 */
	public static function read(string $path): PolicyFileContents {
		$json = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
		if ($json === false) {
			throw new PolicyFileException(['$: cannot read ' . $path]);
		}

		return self::parse($json);
	}

	/**
	 * Parses and validates the text of a policy file.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @throws PolicyFileException When it is not a valid policy file.
	 */
	public static function parse(string $json): PolicyFileContents {
		try {
			/** @psalm-suppress MixedAssignment json_decode() is mixed; validate() checks every part before use. */
			$data = json_decode($json, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new PolicyFileException(['$: not valid JSON (' . $error->getMessage() . ')']);
		}

		$errors = self::validate($data);
		if ($errors !== []) {
			throw new PolicyFileException($errors);
		}

		/** @var array{autoUpdate?: array{enabled?: bool, window?: string, defaultLevel?: string}, policies: array<string, string>} $data */
		$autoUpdate = $data['autoUpdate'] ?? [];
		$policies = $data['policies'];
		ksort($policies);

		return new PolicyFileContents(
			$autoUpdate['enabled'] ?? null,
			$autoUpdate['window'] ?? null,
			$autoUpdate['defaultLevel'] ?? null,
			$policies,
		);
	}

	/**
	 * Every problem in decoded policy-file data, each as `<json path>: <reason>`;
	 * an empty list for a valid file.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 * @return list<string>
	 */
	public static function validate(mixed $data): array {
		if (!is_array($data) || !self::isObject($data)) {
			return ['$: must be an object'];
		}

		$errors = self::unknownKeys($data, self::TOP_KEYS, '$');
		if (($data['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
			$errors[] = '$.schemaVersion: must be ' . self::SCHEMA_VERSION;
		}
		if (array_key_exists('autoUpdate', $data)) {
			$errors = [...$errors, ...self::autoUpdateErrors($data['autoUpdate'])];
		}
		if (!array_key_exists('policies', $data)) {
			$errors[] = '$.policies: is required';
		} else {
			$errors = [...$errors, ...self::policyErrors($data['policies'])];
		}

		return $errors;
	}

	/**
	 * The text of a policy file holding these contents; settings it does not
	 * name are left out.
	 *
	 * @spec openspec/changes/autoupdate-default-and-config-file/specs/auto-update-policies/spec.md#requirement-policies-can-be-kept-in-a-file
	 */
	public static function encode(PolicyFileContents $contents): string {
		$autoUpdate = array_filter([
			'enabled' => $contents->enabled,
			'window' => $contents->window,
			'defaultLevel' => $contents->defaultLevel,
		], static fn (mixed $value): bool => $value !== null);
		$policies = $contents->policies;
		ksort($policies);

		return json_encode([
			'schemaVersion' => self::SCHEMA_VERSION,
			'autoUpdate' => (object)$autoUpdate,
			'policies' => (object)$policies,
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	}

	/**
	 * @return list<string>
	 */
	private static function autoUpdateErrors(mixed $autoUpdate): array {
		if (!self::isObject($autoUpdate)) {
			return ['$.autoUpdate: must be an object'];
		}

		/** @var array<string, mixed> $autoUpdate */
		$errors = self::unknownKeys($autoUpdate, self::AUTO_UPDATE_KEYS, '$.autoUpdate');
		foreach (self::autoUpdateRules() as $key => [$valid, $reason]) {
			if (array_key_exists($key, $autoUpdate) && !$valid($autoUpdate[$key])) {
				$errors[] = '$.autoUpdate.' . $key . ': ' . $reason;
			}
		}

		return $errors;
	}

	/**
	 * Per `autoUpdate` key, the check its value must pass and the reason given when it does not.
	 *
	 * @return array<string, array{0: \Closure(mixed): bool, 1: string}>
	 */
	private static function autoUpdateRules(): array {
		return [
			'enabled' => [static fn (mixed $value): bool => is_bool($value), 'must be true or false'],
			'window' => [static fn (mixed $value): bool => is_string($value) && AutoUpdateWindow::isValid($value), 'must be HH:MM-HH:MM'],
			'defaultLevel' => [static fn (mixed $value): bool => self::isLevel($value), self::levelReason()],
		];
	}

	/**
	 * @return list<string>
	 */
	private static function policyErrors(mixed $policies): array {
		if (!self::isObject($policies)) {
			return ['$.policies: must be an object of app id to level'];
		}

		$errors = [];
		/** @var array<array-key, mixed> $policies */
		foreach (array_keys($policies) as $appId) {
			/** @psalm-suppress MixedAssignment A level is checked by isLevel() before use. */
			$level = $policies[$appId];
			$path = '$.policies.' . $appId;
			if (!AppIdFormat::isValid((string)$appId)) {
				$errors[] = $path . ': is not a valid app id';
			}
			if (!self::isLevel($level)) {
				$errors[] = $path . ': ' . self::levelReason();
			}
		}

		return $errors;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<string> $known
	 * @return list<string>
	 */
	private static function unknownKeys(array $data, array $known, string $path): array {
		$errors = [];
		foreach (array_keys($data) as $key) {
			if (!in_array($key, $known, true)) {
				$errors[] = $path . '.' . $key . ': is not a known key';
			}
		}

		return $errors;
	}

	/** An empty JSON object decodes to an empty array, which is still an object here. */
	private static function isObject(mixed $value): bool {
		return is_array($value) && ($value === [] || !array_is_list($value));
	}

	private static function isLevel(mixed $level): bool {
		return is_string($level) && Policy::isValidLevel($level);
	}

	private static function levelReason(): string {
		return 'must be one of ' . implode(', ', Policy::VALID_LEVELS);
	}
}
