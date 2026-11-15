<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Alert;

use InvalidArgumentException;

/**
 * Validates what an admin enters for a webhook target (releases-new-release-alerts,
 * design D5): a name, an https address without credentials, query or fragment,
 * a format, the alert kinds, an optional secret and the on or off switch.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
 */
final class WebhookTargetInput {
	public const FORMATS = ['json', 'chat'];

	public const KINDS = ['new_release', 'security', 'auto_update', 'pin_drift', 'token'];

	private const MAX_NAME = 100;

	private const MAX_URL = 2000;

	private const REFUSED_ADDRESS_PARTS = ['user' => true, 'pass' => true, 'query' => true, 'fragment' => true];

	/**
	 * The validated fields of a new target, or of an edit merged over the current one.
	 *
	 * @param array<string, mixed> $input The submitted fields.
	 * @param array<string, mixed> $current The stored target, [] for a new one.
	 * @throws InvalidArgumentException naming the first field that is not valid
	 * @return array{name: string, url: string, format: string, kinds: list<string>, enabled: bool}
	 */
	public static function fields(array $input, array $current = []): array {
		$merged = $input + $current;

		return ['url' => self::url($merged['url'] ?? null, ['https'])] + self::stored($merged);
	}

	/**
	 * The fields of a stored target. An address set outside the form (the
	 * e2e fixture sets an http one with `occ config:app:set`) may be http.
	 *
	 * @param array<string, mixed> $stored
	 * @throws InvalidArgumentException naming the first field that is not valid
	 * @return array{name: string, url: string, format: string, kinds: list<string>, enabled: bool}
	 */
	public static function stored(array $stored): array {
		return [
			'name' => self::name($stored['name'] ?? null),
			'url' => self::url($stored['url'] ?? null, ['https', 'http']),
			'format' => self::format($stored['format'] ?? 'json'),
			'kinds' => self::kinds($stored['kinds'] ?? null),
			'enabled' => self::flag($stored['enabled'] ?? true),
		];
	}

	/**
	 * The secret to store: a new non-empty string, null to keep the current one.
	 *
	 * @param array<string, mixed> $input
	 * @throws InvalidArgumentException on a secret that is not a string
	 */
	public static function secret(array $input): ?string {
		$secret = $input['secret'] ?? null;
		if ($secret !== null && !is_string($secret)) {
			throw new InvalidArgumentException('The secret must be text.');
		}

		return ($secret === null || trim($secret) === '') ? null : trim($secret);
	}

	private static function name(mixed $name): string {
		$name = is_string($name) ? trim($name) : '';
		if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
			throw new InvalidArgumentException('Enter a name of at most ' . self::MAX_NAME . ' characters.');
		}

		return $name;
	}

	/**
	 * @param list<string> $schemes
	 */
	private static function url(mixed $url, array $schemes): string {
		$url = is_string($url) ? trim($url) : '';
		$parts = parse_url($url);
		if (strlen($url) > self::MAX_URL || !is_array($parts) || !in_array($parts['scheme'] ?? '', $schemes, true) || ($parts['host'] ?? '') === '') {
			throw new InvalidArgumentException('Enter an https address without a username, password or query.');
		}
		if (array_intersect_key($parts, self::REFUSED_ADDRESS_PARTS) !== []) {
			throw new InvalidArgumentException('Enter an https address without a username, password or query.');
		}

		return $url;
	}

	/**
	 * @return list<string>
	 */
	private static function kinds(mixed $kinds): array {
		$kinds = is_array($kinds) ? array_values(array_unique(array_filter($kinds, 'is_string'))) : [];
		if ($kinds === [] || array_diff($kinds, self::KINDS) !== []) {
			throw new InvalidArgumentException('Pick one or more of: ' . implode(', ', self::KINDS) . '.');
		}

		return $kinds;
	}

	private static function format(mixed $value): string {
		if (!is_string($value) || !in_array($value, self::FORMATS, true)) {
			throw new InvalidArgumentException('The format must be json or chat.');
		}

		return $value;
	}

	private static function flag(mixed $value): bool {
		if (!is_bool($value)) {
			throw new InvalidArgumentException('enabled must be true or false.');
		}

		return $value;
	}
}
