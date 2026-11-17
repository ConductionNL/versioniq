<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Discovery;

use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * GitHub's verified-organisation answer per owner, kept in app config for a
 * week, so discovery asks GitHub once per owner per week.
 *
 * @psalm-api
 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
 */
class PublisherTrustCache {
	/** App config key prefix of the per-owner GitHub organisation answer. */
	public const CACHE_PREFIX = 'publisher.github.';

	/** How long a GitHub organisation answer is reused: one week. */
	public const CACHE_TTL_SECONDS = 604800;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * The cached answer for an owner key when it is younger than a week, otherwise null.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public function get(string $key): ?bool {
		$entry = self::decode($this->appConfig->getValueString(Application::APP_ID, self::CACHE_PREFIX . $key, ''));
		if ($entry === null) {
			return null;
		}

		return ($this->timeFactory->getTime() - $entry['at']) < self::CACHE_TTL_SECONDS ? $entry['verified'] : null;
	}

	/**
	 * Keeps an owner's answer, stamped with the current time.
	 *
	 * @spec openspec/changes/inventory-verified-publisher/specs/app-discovery/spec.md#requirement-hits-and-cards-name-the-publisher-and-the-trust-marks-the-data-backs
	 */
	public function put(string $key, bool $verified): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CACHE_PREFIX . $key,
			json_encode(['verified' => $verified, 'at' => $this->timeFactory->getTime()], JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * A stored entry, or null when it is empty, not JSON or not of the stored shape.
	 *
	 * @return array{verified: bool, at: int}|null
	 */
	private static function decode(string $raw): ?array {
		if ($raw === '') {
			return null;
		}
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		return self::entry($decoded);
	}

	/**
	 * The entry when the decoded value has a boolean answer and an integer time.
	 *
	 * @return array{verified: bool, at: int}|null
	 */
	private static function entry(mixed $decoded): ?array {
		if (!is_array($decoded) || !is_bool($decoded['verified'] ?? null) || !is_int($decoded['at'] ?? null)) {
			return null;
		}

		return ['verified' => $decoded['verified'], 'at' => $decoded['at']];
	}
}
