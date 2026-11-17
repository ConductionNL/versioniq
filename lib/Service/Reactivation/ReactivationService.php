<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Reactivation;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AppVersionChange;
use OCA\Versioniq\Service\Audit\ChangeOrigin;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Settings\InstanceSettings;

/**
 * Decides what an app found disabled means, and acts on it.
 *
 * Disabled while neither its own version nor the server's changed since
 * Versioniq last saw it: someone switched it off on purpose. That is
 * recorded (`external_disable`) and never undone.
 *
 * Disabled while one of them changed: an update left it off, typically a
 * server upgrade disabling an app that does not declare the new major. When
 * the installed version runs on this server and the admin switched automatic
 * re-enabling on, it is enabled again and recorded (`reactivate`); otherwise
 * every admin is told, and the app's card says so.
 *
 * @psalm-import-type SeenApp from SeenStateStore
 * @psalm-api
 */
class ReactivationService {
	public const DELIBERATE = 'deliberate';
	public const REACTIVATED = 'reactivated';
	public const NOTIFIED = 'notified';
	public const IGNORED = 'ignored';

	public function __construct(
		private SeenStateStore $seenState,
		private ExternalChangeRecorder $recorder,
		private ReactivationServices $services,
		private ReactivationReporter $reporter,
	) {
	}

	/**
	 * Whether the admin let Versioniq re-enable apps an update left off. Off by default.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function isAutomatic(): bool {
		return $this->services->config->getValueBool(Application::APP_ID, InstanceSettings::KEY_REACTIVATE_AUTO, false);
	}

	/**
	 * Handles a disable Nextcloud reported for the current process; null
	 * before the first hourly check wrote a baseline.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @return string|null One of the outcome constants, or null without a baseline.
	 */
	public function disabledNow(string $appId): ?string {
		$state = $this->seenState->read();
		if ($state === null) {
			return null;
		}

		return $this->appDisabled(new DisabledApp($appId, $state['apps'][$appId] ?? null, $state['serverVersion']), $this->recorder->origin());
	}

	/**
	 * Handles an app found disabled.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @param DisabledApp $app The app, with what was last seen of it and of the server.
	 * @param ChangeOrigin $origin Who disabled it, as far as known, and what triggered it.
	 * @return string One of the outcome constants.
	 */
	public function appDisabled(DisabledApp $app, ChangeOrigin $origin): string {
		if (!$app->wasEnabled()) {
			// Not enabled as far as Versioniq knows: nothing was turned off.
			return self::IGNORED;
		}

		$version = $this->services->appManager->getAppVersion($app->appId, false);
		$server = $this->services->serverVersion->current();
		$this->seenState->rememberApp($app->appId, ['version' => $version, 'enabled' => false]);

		if ($version === $app->seen['version'] && ($app->seenServer === '' || $server === $app->seenServer)) {
			$this->recorder->recordDisable(new AppVersionChange($app->appId, $version, $version), $origin);

			return self::DELIBERATE;
		}

		return $this->leftByUpdate($app, ['version' => $version, 'server' => $server]);
	}

	/**
	 * Handles an app an update left disabled: enables it again when allowed
	 * and it runs here, else tells every admin.
	 *
	 * @param array{version: string, server: string} $now The installed version and the running server version.
	 */
	private function leftByUpdate(DisabledApp $app, array $now): string {
		['version' => $version, 'server' => $server] = $now;
		$compatible = $this->runsOnThisServer($app->appId, $server);
		if ($compatible && $this->isAutomatic() && $this->enable($app->appId, $version)) {
			return self::REACTIVATED;
		}

		$appChanged = $version !== $app->seen['version'];
		$toVersion = $appChanged ? $version : 'Nextcloud ' . self::major($server);
		$compatibleVersion = $compatible ? null : $this->newestCompatibleVersion($app->appId);
		$this->seenState->markDisabledByUpdate($app->appId, ['toVersion' => $toVersion, 'compatible' => $compatible, 'compatibleVersion' => $compatibleVersion]);
		$this->reporter->leftDisabled($app->appId, [
			'app' => $app->appId,
			'fromVersion' => $appChanged ? (string)$app->seen['version'] : 'Nextcloud ' . self::major($app->seenServer),
			'toVersion' => $toVersion,
			'compatible' => $compatible ? '1' : '0',
			'serverMajor' => self::major($server),
			'compatibleVersion' => $compatibleVersion ?? '',
		]);

		return self::NOTIFIED;
	}

	/**
	 * Whether the installed version declares support for the running server,
	 * by the same check the installers use.
	 */
	private function runsOnThisServer(string $appId, string $server): bool {
		$info = $this->services->appManager->getAppInfo($appId);
		if ($info === null) {
			return false;
		}

		try {
			return $this->services->appManager->isAppCompatible($server, $info);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Enables the app and records the outcome; true when it is enabled.
	 */
	private function enable(string $appId, string $version): bool {
		$change = new AppVersionChange($appId, null, $version);
		try {
			$this->services->appManager->enableApp($appId);
		} catch (\Throwable $error) {
			$this->recorder->recordReactivate($change, $error->getMessage());
			$this->reporter->enableFailed($appId, $error->getMessage());

			return false;
		}
		$this->recorder->recordReactivate($change, null);
		$this->seenState->rememberApp($appId, ['version' => $version, 'enabled' => true]);

		return true;
	}

	/**
	 * The newest version that runs here, when the pending-updates snapshot names one.
	 */
	private function newestCompatibleVersion(string $appId): ?string {
		/** @var mixed $entry */
		$entry = $this->services->availability?->read()['updates'][$appId] ?? null;
		if (!is_array($entry)) {
			return null;
		}
		/** @var mixed $version */
		$version = $entry['newestCompatibleVersion'] ?? null;

		return is_string($version) && $version !== '' ? $version : null;
	}

	private static function major(string $version): string {
		return explode('.', $version, 2)[0];
	}
}
