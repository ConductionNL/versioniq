<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Channel;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The release channels an admin set: the instance default under
 * `channel.default`, an app's own under `channel.{appId}`, and an app's marked
 * long-term support lines under `lts_lines.{appId}` as a JSON list. A
 * malformed value reads as absent; an absent default is `server` (design D2).
 *
 * @psalm-api
 */
class ChannelStore {
	private const KEY_DEFAULT = 'channel.default';
	private const KEY_APP_PREFIX = 'channel.';
	private const KEY_LINES_PREFIX = 'lts_lines.';
	private const JSON_DEPTH = 4;

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * The instance default channel; `server` when unset or malformed.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function defaultChannel(): string {
		return $this->readChannel(self::KEY_DEFAULT) ?? ReleaseChannel::SERVER;
	}

	/**
	 * An app's own channel, or null when it follows the default.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function appChannel(string $appId): ?string {
		return $this->readChannel(self::KEY_APP_PREFIX . $appId);
	}

	/**
	 * The channel an app follows: its own, else the default.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function effectiveChannel(string $appId): string {
		return $this->appChannel($appId) ?? $this->defaultChannel();
	}

	/**
	 * Sets the instance default.
	 *
	 * @throws InvalidArgumentException For an unknown channel.
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function setDefault(string $channel): void {
		$this->config->setValueString(Application::APP_ID, self::KEY_DEFAULT, self::known($channel));
	}

	/**
	 * Sets an app's own channel.
	 *
	 * @throws InvalidArgumentException For an unknown channel.
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function setAppChannel(string $appId, string $channel): void {
		$this->config->setValueString(Application::APP_ID, self::KEY_APP_PREFIX . $appId, self::known($channel));
	}

	/**
	 * Clears an app's own channel, so it follows the default again.
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	public function clearAppChannel(string $appId): void {
		$this->config->deleteKey(Application::APP_ID, self::KEY_APP_PREFIX . $appId);
	}

	/**
	 * The lines an admin marked for an app; invalid entries are left out.
	 *
	 * @return list<string>
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
	 */
	public function ltsLines(string $appId): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY_LINES_PREFIX . $appId, '');
		if ($raw === '') {
			return [];
		}
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}

		return is_array($decoded) ? self::validLines($decoded) : [];
	}

	/**
	 * Sets an app's marked lines; an empty list clears them.
	 *
	 * @param list<string> $lines
	 * @throws InvalidArgumentException For a value that is not `major` or `major.minor`.
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
	 */
	public function setLtsLines(string $appId, array $lines): void {
		$key = self::KEY_LINES_PREFIX . $appId;
		$clean = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if (!ReleaseChannel::isLine($line)) {
				throw new InvalidArgumentException('A long-term support line is a major or major.minor version.');
			}
			if (!in_array($line, $clean, true)) {
				$clean[] = $line;
			}
		}
		if ($clean === []) {
			$this->config->deleteKey(Application::APP_ID, $key);

			return;
		}
		$this->config->setValueString(Application::APP_ID, $key, json_encode($clean, JSON_THROW_ON_ERROR));
	}

	private function readChannel(string $key): ?string {
		$value = trim($this->config->getValueString(Application::APP_ID, $key, ''));

		return ReleaseChannel::isKnown($value) ? $value : null;
	}

	/**
	 * @throws InvalidArgumentException
	 */
	private static function known(string $channel): string {
		if (!ReleaseChannel::isKnown($channel)) {
			throw new InvalidArgumentException('Unknown release channel: ' . $channel);
		}

		return $channel;
	}

	/**
	 * @param array<array-key, mixed> $values
	 * @return list<string>
	 */
	private static function validLines(array $values): array {
		$lines = [];
		/** @var mixed $value */
		foreach ($values as $value) {
			if (is_string($value) && ReleaseChannel::isLine($value) && !in_array($value, $lines, true)) {
				$lines[] = $value;
			}
		}

		return $lines;
	}
}
