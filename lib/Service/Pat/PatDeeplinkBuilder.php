<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pat;

use InvalidArgumentException;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Service\Source\ForgeRegistry;
use OCP\IRequest;

/**
 * Builds prefilled token-creation URLs per forge/kind. GitHub classic PATs
 * accept full scope+description prefill; GitHub fine-grained PATs and Codeberg
 * (Forgejo) tokens only accept the page link, so we return a structured
 * `instructions` array for the UI to render.
 *
 * @psalm-api
 */
class PatDeeplinkBuilder {
	private const CLASSIC_BASE = 'https://github.com/settings/tokens/new';
	private const FINE_GRAINED_BASE = 'https://github.com/settings/personal-access-tokens/new';

	public function __construct(
		private IRequest $request,
		private ForgeRegistry $forgeRegistry,
	) {
	}

	/**
	 * Builds a prefilled token-creation deeplink per kind; see "PAT management API" (deeplink scenarios).
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return array{kind:string, url:string, instructions:list<string>}
	 */
	public function build(string $kind, string $forge = ForgeRegistry::FORGE_CODEBERG): array {
		return match ($kind) {
			Pat::KIND_CLASSIC => $this->buildClassic(),
			Pat::KIND_FINE_GRAINED => $this->buildFineGrained(),
			Pat::KIND_FORGE_TOKEN => $this->buildForgeToken($forge),
			default => throw new InvalidArgumentException('Unknown PAT kind: ' . $kind),
		};
	}

	/**
	 * The token page of a Forgejo or Gitea forge: the configured self-hosted
	 * host, or codeberg.org for the retired `codeberg` forge (issue #437).
	 *
	 * @return array{kind:string, url:string, instructions:list<string>}
	 * @throws InvalidArgumentException when the forge is GitHub, unknown, or
	 *                                  the self-hosted host is not configured
	 */
	private function buildForgeToken(string $forge): array {
		if ($forge === ForgeRegistry::FORGE_GITHUB) {
			throw new InvalidArgumentException('A forge-token link is for a Forgejo or Gitea forge, not GitHub.');
		}

		return [
			'kind' => Pat::KIND_FORGE_TOKEN,
			'url' => $this->forgeRegistry->get($forge)->tokenCreateUrl,
			'instructions' => [
				'Open the link to create an access token on your Forgejo or Gitea host (Settings, Applications, Manage access tokens).',
				'Give it a name like "Nextcloud Versioniq" and select read-only repository scopes only.',
				'Set an expiration if your policy requires one.',
				'Click "Generate Token" and paste the resulting value back into Versioniq.',
			],
		];
	}

	/**
	 * @return array{kind:string, url:string, instructions:list<string>}
	 */
	private function buildClassic(): array {
		$description = 'Nextcloud Versioniq - ' . $this->describeHost();
		$query = http_build_query([
			'scopes' => 'repo',
			'description' => $description,
		]);

		return [
			'kind' => Pat::KIND_CLASSIC,
			'url' => self::CLASSIC_BASE . '?' . $query,
			'instructions' => [
				'Click the link to open GitHub with the recommended scope (`repo`) and description prefilled.',
				'Set an expiration of 90 days or less.',
				'Click "Generate token" and paste the resulting `ghp_...` value back into Versioniq.',
			],
		];
	}

	/**
	 * @return array{kind:string, url:string, instructions:list<string>}
	 */
	private function buildFineGrained(): array {
		return [
			'kind' => Pat::KIND_FINE_GRAINED,
			'url' => self::FINE_GRAINED_BASE,
			'instructions' => [
				'Repository access: choose "Only select repositories" and pick the ones Versioniq should install from.',
				'Permissions → Repository permissions → Contents: Read-only.',
				'Metadata: Read-only (this is auto-included; do not change).',
				'Set an expiration of 90 days or less.',
				'Click "Generate token" and paste the resulting `github_pat_...` value back into Versioniq.',
			],
		];
	}

	private function describeHost(): string {
		$host = $this->request->getServerHost();

		return $host !== '' ? $host : 'Nextcloud';
	}
}
