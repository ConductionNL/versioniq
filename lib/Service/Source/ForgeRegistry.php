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
 * Forges (issue #437):
 * - `github`: GitHub, or GitHub Enterprise through the overrides.
 * - `forgejo`: a self-hosted Forgejo or Gitea host. It has no public default:
 *   it exists only once `forge.forgejo.web_base` names a host, which the admin
 *   sets on the Sources tab. The API base is `{web_base}/api/v1` unless
 *   `forge.forgejo.api_base` overrides it.
 * - `codeberg`: RETIRED. Codeberg is no longer offered as a forge of its own,
 *   but bindings, tokens and trusted patterns stored under `codeberg` keep
 *   resolving to codeberg.org through the same Forgejo API shape, so nothing
 *   an admin already stored breaks. It is left out of {@see selectableIds()}.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
class ForgeRegistry {
	public const FORGE_GITHUB = 'github';
	public const FORGE_FORGEJO = 'forgejo';
	public const FORGE_CODEBERG = 'codeberg';

	/** Public GitHub; overridden by `forge.github.api_base` / `web_base` for GitHub Enterprise. */
	public const GITHUB_API_DEFAULT = 'https://api.github.com';
	public const GITHUB_WEB_DEFAULT = 'https://github.com';

	/**
	 * Forges kept only so stored data keeps working; never offered for new
	 * bindings, tokens or trusted patterns.
	 */
	private const RETIRED = [self::FORGE_CODEBERG];

	private const DEFAULTS = [
		self::FORGE_GITHUB => [
			'api' => self::GITHUB_API_DEFAULT,
			'web' => self::GITHUB_WEB_DEFAULT,
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
		$this->registerForgejo();
	}

	/**
	 * Registers the self-hosted Forgejo or Gitea forge when a host is set,
	 * and removes it when none is.
	 */
	private function registerForgejo(): void {
		unset($this->forges[self::FORGE_FORGEJO]);
		$web = $this->baseUrl(self::FORGE_FORGEJO, 'web_base', '');
		if ($web === '') {
			return;
		}

		$this->forges[self::FORGE_FORGEJO] = new Forge(
			self::FORGE_FORGEJO,
			$this->baseUrl(self::FORGE_FORGEJO, 'api_base', $web . '/api/v1'),
			$web,
			Forge::SCHEME_TOKEN,
			false,
			$web . '/user/settings/applications',
		);
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
			if ($forgeId === self::FORGE_FORGEJO) {
				throw new InvalidArgumentException('No self-hosted Forgejo or Gitea host is configured. Set it on the Sources tab first.');
			}
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

	/**
	 * Whether a forge is kept only for stored data and never offered anew.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function isRetired(string $forgeId): bool {
		return in_array($forgeId, self::RETIRED, true);
	}

	/**
	 * The forges an admin may pick for a new binding, token or trusted
	 * pattern: every registered forge that is not retired.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return list<string>
	 */
	public function selectableIds(): array {
		return array_values(array_filter(
			array_keys($this->forges),
			fn (string $id): bool => !$this->isRetired($id),
		));
	}

	/**
	 * The configured self-hosted Forgejo or Gitea host (its web base URL), or
	 * an empty string when none is set.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function forgejoHost(): string {
		return isset($this->forges[self::FORGE_FORGEJO]) ? $this->forges[self::FORGE_FORGEJO]->webBaseUrl : '';
	}

	/**
	 * Sets or clears the self-hosted Forgejo or Gitea host. An empty value
	 * clears it. A host must be an https URL with no credentials, query or
	 * fragment: tokens are sent to it. A stale `api_base` override is dropped
	 * so the API base follows the new host.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @throws InvalidArgumentException when the host is not acceptable
	 */
	public function setForgejoHost(string $host): void {
		$host = rtrim(trim($host), '/');
		if ($host !== '') {
			$parts = parse_url($host);
			if ($parts === false
				|| ($parts['scheme'] ?? '') !== 'https'
				|| ($parts['host'] ?? '') === ''
				|| isset($parts['user'])
				|| isset($parts['pass'])
				|| isset($parts['query'])
				|| isset($parts['fragment'])
			) {
				throw new InvalidArgumentException('The host must be an https address such as https://git.example.org, without a username, password or query.');
			}
		}

		$this->appConfig->deleteKey(Application::APP_ID, 'forge.' . self::FORGE_FORGEJO . '.api_base');
		if ($host === '') {
			$this->appConfig->deleteKey(Application::APP_ID, 'forge.' . self::FORGE_FORGEJO . '.web_base');
		} else {
			$this->appConfig->setValueString(Application::APP_ID, 'forge.' . self::FORGE_FORGEJO . '.web_base', $host);
		}
		$this->registerForgejo();
	}
}
