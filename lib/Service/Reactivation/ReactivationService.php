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
use OCA\Versioniq\Service\AdminNotice;
use OCA\Versioniq\Service\AdminNotificationServices;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Audit\ExternalChangeRecorder;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Availability\AvailabilityResultStore;
use OCA\Versioniq\Service\NotifiesAdmins;
use OCA\Versioniq\Service\ResolvesAdminUids;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
	use NotifiesAdmins;
	use ResolvesAdminUids;

	public const DELIBERATE = 'deliberate';
	public const REACTIVATED = 'reactivated';
	public const NOTIFIED = 'notified';
	public const IGNORED = 'ignored';

	public function __construct(
		private SeenStateStore $seenState,
		private ExternalChangeRecorder $recorder,
		private IAppManager $appManager,
		private ServerVersionProvider $serverVersion,
		private IAppConfig $config,
		private AdminNotificationServices $admin,
		private LoggerInterface $logger,
		private ?AvailabilityResultStore $availability = null,
	) {
	}

	/**
	 * Whether the admin let Versioniq re-enable apps an update left off. Off by default.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function isAutomatic(): bool {
		return $this->config->getValueBool(Application::APP_ID, InstanceSettings::KEY_REACTIVATE_AUTO, false);
	}

	/**
	 * Handles an app found disabled.
	 *
	 * @spec openspec/changes/audit-external-changes
	 * @param SeenApp|null $seen What was last seen of the app, before this disable.
	 * @param string $seenServer The server version last seen.
	 * @param string $actor Who disabled it, as far as known.
	 * @param string $trigger What triggered it.
	 * @return string One of the outcome constants.
	 */
	public function appDisabled(string $appId, ?array $seen, string $seenServer, string $actor, string $trigger): string {
		if ($seen === null || !$seen['enabled']) {
			// Not enabled as far as Versioniq knows: nothing was turned off.
			return self::IGNORED;
		}

		$version = $this->appManager->getAppVersion($appId, false);
		$server = $this->serverVersion->current();
		$appChanged = $version !== $seen['version'];
		$serverChanged = $seenServer !== '' && $server !== $seenServer;
		$this->seenState->rememberApp($appId, $version, false);

		if (!$appChanged && !$serverChanged) {
			$this->recorder->recordDisable($appId, $version, $trigger, $actor);

			return self::DELIBERATE;
		}

		$compatible = $this->runsOnThisServer($appId, $server);
		if ($compatible && $this->isAutomatic() && $this->enable($appId, $version)) {
			return self::REACTIVATED;
		}

		$toVersion = $appChanged ? $version : 'Nextcloud ' . self::major($server);
		$compatibleVersion = $compatible ? null : $this->newestCompatibleVersion($appId);
		$this->seenState->markDisabledByUpdate($appId, ['toVersion' => $toVersion, 'compatible' => $compatible, 'compatibleVersion' => $compatibleVersion]);
		$this->notifyAdmins((new AdminNotice())
			->withObject('app_left_disabled', $appId)
			->withSubject('app_left_disabled', [
				'app' => $appId,
				'fromVersion' => $appChanged ? $seen['version'] : 'Nextcloud ' . self::major($seenServer),
				'toVersion' => $toVersion,
				'compatible' => $compatible ? '1' : '0',
				'serverMajor' => self::major($server),
				'compatibleVersion' => $compatibleVersion ?? '',
			])
			->loggedAs('ReactivationService: failed to raise the left-disabled notification', ['appId' => $appId]));

		return self::NOTIFIED;
	}

	/**
	 * Whether the installed version declares support for the running server,
	 * by the same check the installers use.
	 */
	private function runsOnThisServer(string $appId, string $server): bool {
		$info = $this->appManager->getAppInfo($appId);
		if ($info === null) {
			return false;
		}

		try {
			return $this->appManager->isAppCompatible($server, $info);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Enables the app and records the outcome; true when it is enabled.
	 */
	private function enable(string $appId, string $version): bool {
		try {
			$this->appManager->enableApp($appId);
		} catch (\Throwable $error) {
			$this->recorder->recordReactivate($appId, $version, false, 'Could not enable the app after an update: ' . $error->getMessage());
			$this->logger->warning('ReactivationService: could not re-enable an app an update left disabled', ['appId' => $appId, 'message' => $error->getMessage()]);

			return false;
		}
		$this->recorder->recordReactivate($appId, $version, true, 'Enabled again after an update left it disabled.');
		$this->seenState->rememberApp($appId, $version, true);

		return true;
	}

	/**
	 * The newest version that runs here, when the pending-updates snapshot names one.
	 */
	private function newestCompatibleVersion(string $appId): ?string {
		/** @var mixed $entry */
		$entry = $this->availability?->read()['updates'][$appId] ?? null;
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
