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

/**
 * Maps a `SourceBinding` to the concrete driver that knows how to talk to
 * that origin. Drivers are stateless DI singletons; the binding carries the
 * per-app configuration (owner/repo/assetPattern) into the driver.
 *
 * @psalm-api
 */
class SourceRegistry {
	public function __construct(
		private AppStoreSource $appStore,
		private ForgeReleaseSource $forgeSource,
	) {
	}

	/**
	 * Resolves a binding to its concrete source driver; see "Source abstraction".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function get(SourceBinding $binding): SourceInterface {
		return match ($binding->kind) {
			SourceBinding::KIND_APPSTORE => $this->appStore,
			// One driver serves all forges; it reads the forge from the binding.
			SourceBinding::KIND_GITHUB_RELEASE => $this->forgeSource,
			default => throw new InvalidArgumentException('Unsupported source kind: ' . $binding->kind),
		};
	}

	/**
	 * Lists the registered source kinds for the UI; see "Source management API".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return list<array{id: string, kind: string, label: string}>
	 */
	public function listAvailable(): array {
		return [
			[
				'id' => 'appstore',
				'kind' => SourceBinding::KIND_APPSTORE,
				'label' => 'Nextcloud App Store',
			],
			[
				'id' => 'github',
				'kind' => SourceBinding::KIND_GITHUB_RELEASE,
				'label' => 'GitHub Releases (public)',
			],
			// Codeberg is retired as a source of its own (issue #437); another
			// Forgejo or Gitea host, Codeberg included, is the forgejo forge.
			[
				'id' => 'forgejo',
				'kind' => SourceBinding::KIND_GITHUB_RELEASE,
				'label' => 'Self-hosted Forgejo or Gitea releases',
			],
		];
	}

	/**
	 * Parses a source-id string (`appstore` / `github:owner/repo`) into a binding; see "Explicit source override".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public static function parseSourceId(string $sourceId): SourceBinding {
		$sourceId = trim($sourceId);
		if ($sourceId === '' || $sourceId === 'appstore') {
			return SourceBinding::appStore();
		}

		foreach (SourceBinding::FORGES as $forge) {
			$prefix = $forge . ':';
			if (!str_starts_with($sourceId, $prefix)) {
				continue;
			}
			$ownerRepo = substr($sourceId, strlen($prefix));
			if (!str_contains($ownerRepo, '/')) {
				throw new InvalidArgumentException(sprintf('%s source id must be of the form %s:owner/repo', $forge, $forge));
			}
			$parts = explode('/', $ownerRepo, 2);
			$owner = $parts[0];
			$repo = $parts[1] ?? '';
			if ($owner === '' || $repo === '') {
				throw new InvalidArgumentException(sprintf('%s source id has empty owner or repo', $forge));
			}

			return SourceBinding::release($forge, $owner, $repo);
		}

		throw new InvalidArgumentException('Unknown source id: ' . $sourceId);
	}
}
