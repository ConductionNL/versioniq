<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\ServerVersion;
use Throwable;

/**
 * "Enable anyway": enables a disabled app whose installed version only its
 * declared maximum keeps off this server, by putting it on
 * `app_install_overwrite` first. A failed enable removes the entry again when
 * Versioniq added it; a success records the override and writes its audit row.
 *
 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
 */
class ForceEnabler {
	public function __construct(
		private ForceOverrideStore $overrides,
		private IAppManager $appManager,
		private ServerVersion $serverVersion,
	) {
	}

	/**
	 * Force-enables the app for the given admin.
	 *
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 *
	 * @spec openspec/changes/install-force-incompatible/specs/version-management/spec.md
	 */
	public function enable(string $appId, string $actorUid): array {
		$refusal = $this->refusal($appId);
		if ($refusal !== null) {
			return $refusal;
		}

		$added = $this->overrides->apply($appId);
		try {
			$this->appManager->enableApp($appId);
		} catch (Throwable $error) {
			if ($added) {
				$this->overrides->remove($appId);
			}

			return self::answer(Http::STATUS_CONFLICT, ['appId' => $appId, 'message' => 'Nextcloud did not enable the app: ' . $error->getMessage()]);
		}

		$major = $this->serverVersion->getMajorVersion();
		$this->overrides->record($appId, $this->appManager->getAppVersion($appId, false), $major, $actorUid, $added);

		return self::answer(Http::STATUS_OK, ['appId' => $appId, 'enabled' => true, 'forcedOn' => $major]);
	}

	/**
	 * Why the app cannot be force-enabled, or null when it can: it must be
	 * installed, disabled, not shipped with the server, and kept out by its
	 * maximum only.
	 *
	 * @return array{statusCode: int, payload: array<string, mixed>}|null
	 */
	private function refusal(string $appId): ?array {
		if (!AppIdFormat::isValid($appId) || !$this->appManager->isInstalled($appId)) {
			return self::answer(Http::STATUS_NOT_FOUND, ['appId' => $appId, 'message' => 'This app is not installed.']);
		}
		if ($this->appManager->isShipped($appId)) {
			return self::answer(Http::STATUS_FORBIDDEN, ['appId' => $appId, 'message' => 'This core app cannot be managed from Versioniq.']);
		}
		if ($this->appManager->isEnabledForAnyone($appId)) {
			return self::answer(Http::STATUS_CONFLICT, ['appId' => $appId, 'message' => 'This app is already enabled.']);
		}
		if (!DeclaredServerRange::serverTooNew($this->serverVersion->getVersionString(), $this->appManager->getAppInfo($appId))) {
			return self::answer(Http::STATUS_CONFLICT, [
				'appId' => $appId,
				'message' => 'Only an app whose declared maximum is below this Nextcloud can be enabled anyway; an override cannot help this one.',
			]);
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private static function answer(int $statusCode, array $payload): array {
		return ['statusCode' => $statusCode, 'payload' => $payload];
	}
}
