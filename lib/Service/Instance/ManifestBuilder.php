<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Instance;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Kernel\Contracts\ManifestBuilderInterface;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\InstallerService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * This instance's manifest: what it runs, from local state only
 * (install-multi-instance-promotion design D3). It makes no outbound call and
 * carries no secrets, tokens or file paths.
 *
 * @spec openspec/changes/install-multi-instance-promotion/design.md
 *
 * @psalm-type ManifestApp = array{appId:string,installedVersion:?string,state:string,sourceId:?string,sha256:?string,runningSince:?string}
 * @psalm-type InstanceManifest = array{instanceName:string,stage:string,serverVersion:string,versioniqVersion:string,apps:list<ManifestApp>}
 */
class ManifestBuilder implements ManifestBuilderInterface {
	public function __construct(
		private InstallerService $installer,
		private InstanceConnectionStore $connections,
		private AuditEntryMapper $audit,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private ServerVersionProvider $serverVersion,
	) {
	}

	/**
	 * The manifest of this instance.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/design.md
	 * @return InstanceManifest
	 */
	public function build(): array {
		$self = $this->connections->self();
		$apps = [];
		foreach ($this->installer->getInstalledApps() as $card) {
			$apps[] = $this->appEntry($card);
		}

		return [
			'instanceName' => $self['name'] !== '' ? $self['name'] : $this->hostName(),
			'stage' => $self['stage'],
			'serverVersion' => $this->serverVersion->current(),
			'versioniqVersion' => $this->appManager->getAppVersion(Application::APP_ID),
			'apps' => $apps,
		];
	}

	/**
	 * One app's manifest entry.
	 *
	 * @param array{id:string,boundSourceId:?string,installedVersion:?string,state:string,lkg:?array{version:string,recordedAt:string,sourceId:?string}} $card
	 * @return ManifestApp
	 */
	private function appEntry(array $card): array {
		$version = $card['installedVersion'];
		$sha = null;
		if ($version !== null && $version !== '') {
			$sha = $this->installer->getBinding($card['id'])?->getRecordedSha($version);
		}

		return [
			'appId' => $card['id'],
			'installedVersion' => $version,
			'state' => $card['state'],
			'sourceId' => $card['boundSourceId'],
			'sha256' => $sha,
			'runningSince' => $this->runningSince($card['id'], $version, $card['lkg']),
		];
	}

	/**
	 * Since when the installed version runs: the last-known-good record's time
	 * when it is for this version, else the newest successful audit row that
	 * brought the app to it, else null. It never guesses.
	 *
	 * @param ?array{version:string,recordedAt:string,sourceId:?string} $lkg
	 */
	private function runningSince(string $appId, ?string $version, ?array $lkg): ?string {
		if ($version === null || $version === '') {
			return null;
		}

		if ($lkg !== null && $lkg['version'] === $version) {
			return $lkg['recordedAt'];
		}

		$entry = $this->audit->findNewestSuccessTo($appId, $version);
		if ($entry === null) {
			return null;
		}

		$at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entry->getCreatedAt(), new \DateTimeZone('UTC'));

		return $at === false ? null : $at->format(\DateTimeInterface::ATOM);
	}

	/** The host of this instance's absolute URL. */
	private function hostName(): string {
		$host = parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_HOST);

		return is_string($host) ? $host : '';
	}
}
