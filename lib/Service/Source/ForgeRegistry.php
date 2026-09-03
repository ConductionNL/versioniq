<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Holds the known git forges. Adding a forge is a config entry here, not a new
 * class — the driver and validator read {@see Forge} fields.
 *
 * Each forge's API and web base URLs default to the public host but MAY be
 * overridden through app config, so an instance can point a forge at a
 * self-hosted deployment (GitHub Enterprise, a private Forgejo/Gitea) — or, in
 * an e2e environment, at a fixture. The override keys are
 * `forge.{forgeId}.api_base` and `forge.{forgeId}.web_base`; absent keys keep
 * the public defaults, so existing instances are unaffected.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
class ForgeRegistry {
	public const FORGE_GITHUB = 'github';
	public const FORGE_CODEBERG = 'codeberg';

	private const DEFAULTS = [
		self::FORGE_GITHUB => [
			'api' => 'https://api.github.com',
			'web' => 'https://github.com',
			'scheme' => Forge::SCHEME_BEARER,
			'exposesScopeHeader' => true,
			'tokenCreateUrl' => 'https://github.com/settings/tokens',
		],
		self::FORGE_CODEBERG => [
			'api' => 'https://codeberg.org/api/v1',
			'web' => 'https://codeberg.org',
			'scheme' => Forge::SCHEME_TOKEN,
			'exposesScopeHeader' => false,
			'tokenCreateUrl' => 'https://codeberg.org/user/settings/applications',
		],
	];

	/** @var array<string, Forge> */
	private array $forges;

	public function __construct(
		private IAppConfig $appConfig,
	) {
		$this->forges = [];
		foreach (self::DEFAULTS as $id => $d) {
			$this->forges[$id] = new Forge(
				$id,
				$this->baseUrl($id, 'api_base', $d['api']),
				$this->baseUrl($id, 'web_base', $d['web']),
				$d['scheme'],
				$d['exposesScopeHeader'],
				$d['tokenCreateUrl'],
			);
		}
	}

	/**
	 * Resolves a forge base URL, preferring the app-config override and falling
	 * back to the public default. A blank or whitespace override is ignored, and
	 * a trailing slash is trimmed so endpoint building stays consistent.
	 */
	private function baseUrl(string $forgeId, string $key, string $default): string {
		/** @var string|null $raw */
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'forge.' . $forgeId . '.' . $key, '');
		$override = trim((string)$raw);

		return rtrim($override !== '' ? $override : $default, '/');
	}

	public function has(string $forgeId): bool {
		return isset($this->forges[$forgeId]);
	}

	/**
	 * @throws InvalidArgumentException when the forge is unknown
	 */
	public function get(string $forgeId): Forge {
		if (!isset($this->forges[$forgeId])) {
			throw new InvalidArgumentException('Unknown forge: ' . $forgeId);
		}

		return $this->forges[$forgeId];
	}

	/**
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys($this->forges);
	}
}
