<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Inventory;

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\App\IAppManager;
use Throwable;

/**
 * The instance's software inventory: the server and every installed app with
 * its version, licence, source and recorded SHA-256 (inventory-export-sbom-metrics
 * D1). The SBOM writers and the metrics family read this one list, so they
 * cannot disagree.
 *
 * @psalm-type InventoryApp = array{id:string,name:string,version:string,licence:?string,shipped:bool,source:string,sha256:?string,purl:string}
 * @psalm-type Inventory = array{server:array{name:string,version:string,purl:string},apps:list<InventoryApp>}
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
 */
class InventoryBuilder {
	public function __construct(
		private readonly InstallerService $installer,
		private readonly SourceBindingStore $bindings,
		private readonly IAppManager $appManager,
		private readonly ServerVersionProvider $serverVersion,
	) {
	}

	/**
	 * The server and every app that is installed (enabled or disabled).
	 *
	 * @return Inventory
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
	 */
	public function build(): array {
		$serverVersion = $this->serverVersion->current();
		$apps = [];
		foreach ($this->installer->getInstalledApps() as $card) {
			if ($card['state'] === 'notInstalled' || $card['installedVersion'] === null) {
				continue;
			}

			$apps[] = $this->appEntry(card: $card);
		}

		return [
			'server' => [
				'name' => 'nextcloud-server',
				'version' => $serverVersion,
				'purl' => 'pkg:generic/nextcloud-server@' . rawurlencode($serverVersion),
			],
			'apps' => $apps,
		];
	}

	/**
	 * One app's inventory entry.
	 *
	 * @param array{id:string,label:string,isShipped:bool,installedVersion:?string} $card The Apps-tab card.
	 *
	 * @return InventoryApp
	 */
	private function appEntry(array $card): array {
		$version = (string)$card['installedVersion'];
		$binding = $this->binding(appId: $card['id']);
		$ownerRepo = $binding?->getOwnerRepo();

		return [
			'id' => $card['id'],
			'name' => $card['label'],
			'version' => $version,
			'licence' => $this->licence(appId: $card['id']),
			'shipped' => $card['isShipped'],
			'source' => self::sourceLabel(binding: $binding, ownerRepo: $ownerRepo),
			'sha256' => $binding?->getRecordedSha($version),
			'purl' => self::purl(appId: $card['id'], version: $version, binding: $binding, ownerRepo: $ownerRepo),
		];
	}

	/**
	 * The app's binding, or null for an App Store app; a binding that cannot be read counts as none.
	 *
	 * @param string $appId The app.
	 *
	 * @return SourceBinding|null
	 */
	private function binding(string $appId): ?SourceBinding {
		try {
			return $this->bindings->get($appId);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * The first licence the app's info.xml declares, or null.
	 *
	 * @param string $appId The app.
	 *
	 * @return string|null
	 */
	private function licence(string $appId): ?string {
		try {
			$info = $this->appManager->getAppInfo($appId);
		} catch (Throwable) {
			return null;
		}

		$licence = $info['licence'] ?? null;
		if (is_array($licence) === true) {
			$licence = reset($licence);
		}

		if (is_string($licence) === false || trim($licence) === '') {
			return null;
		}

		return trim($licence);
	}

	/**
	 * Where the app comes from: `appstore`, or `<forge>:<owner>/<repo>` for a bound release.
	 *
	 * @param SourceBinding|null $binding   The binding.
	 * @param string|null        $ownerRepo Its owner/repo.
	 *
	 * @return string
	 */
	private static function sourceLabel(?SourceBinding $binding, ?string $ownerRepo): string {
		if ($binding === null || $ownerRepo === null) {
			return 'appstore';
		}

		return $binding->getForge() . ':' . $ownerRepo;
	}

	/**
	 * The package URL (design D2): pkg:github for a GitHub-bound app, pkg:generic otherwise.
	 *
	 * @param string             $appId     The app.
	 * @param string             $version   Its version.
	 * @param SourceBinding|null $binding   Its binding.
	 * @param string|null        $ownerRepo The binding's owner/repo.
	 *
	 * @return string
	 */
	private static function purl(string $appId, string $version, ?SourceBinding $binding, ?string $ownerRepo): string {
		if ($binding !== null && $ownerRepo !== null && $binding->getForge() === SourceBinding::FORGE_GITHUB) {
			[$owner, $repo] = explode('/', $ownerRepo, 2);

			return 'pkg:github/' . rawurlencode(strtolower($owner)) . '/' . rawurlencode(strtolower($repo)) . '@' . rawurlencode($version);
		}

		return 'pkg:generic/nextcloud-app/' . rawurlencode($appId) . '@' . rawurlencode($version);
	}
}
