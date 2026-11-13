<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

use Exception;
use OC\AppFramework\Bootstrap\Coordinator;
use OC\DB\Connection;
use OC\DB\MigrationService;
use OCA\Versioniq\Service\Audit\SeenStateStore;
use OCA\Versioniq\Service\Lkg\Lkg;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Settings\ConfigStores;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Finalizes the post-extract phase of an app install: migrations, repair steps,
 * background-job registration, remote/public route registration, and the
 * `installed_version` / `enabled` config writes.
 *
 * Used by both `SelectedReleaseInstallerService` (signed App Store path) and
 * `ExternalReleaseInstallerService` (unsigned GitHub-release path) so the two
 * installers cannot drift on the migration semantics that determine whether an
 * upgrade actually completes.
 *
 * @psalm-api
 */
class InstallFinalizer {
	public function __construct(
		/**
		 * `configs->config` only for the handful of keys Nextcloud core owns
		 * and writes itself. IConfig::setAppValue() is deprecated, but it is
		 * the sole public way to store a value untyped (VALUE_MIXED), and
		 * matching core's own type is the entire point. See the comment on
		 * the writes below.
		 */
		private ConfigStores $configs,
		private IAppManager $appManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private LkgStore $lkgStore,
		private ITimeFactory $timeFactory,
		/**
		 * Runs an update's finalize in a fresh process (#611). Null in tests
		 * that build this class by hand: finalize() then stays in-process.
		 */
		private ?ChildProcessFinalizer $childFinalizer = null,
		/**
		 * What Versioniq last saw of every app (audit-external-changes): its
		 * own install updates it, so the hourly check never records it again.
		 */
		private ?SeenStateStore $seenState = null,
	) {
	}

	/**
	 * Runs migrations, repair steps, job + route registration, and version/enabled writes after extraction;
	 * see "Install Specific Version" ("any database migrations for the new version MUST be triggered") and
	 * "Last-known-good version record" (the last statement here — reached only on success — is the single
	 * choke point that writes `lkg.{appId}` for both installers).
	 *
	 * An update runs all of that in a child process that boots with the new files in place, so the repair
	 * steps see the new version's registrations and not the previous version's (#611); the child calls this
	 * method again and stays in-process. A fresh install, or an update without a way to start a child, runs
	 * in this process. `$output` is used only when this process finalizes; the child gets none.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` for the just-extracted version.
	 * @param string $sourceId The source binding id (e.g. `appstore`, `github:owner/repo`) this version was
	 *                         installed from, recorded alongside the last-known-good version.
	 * @throws Exception
	 */
	public function finalize(string $appPath, array $info, string $enabled, ?IOutput $output = null, string $sourceId = 'appstore'): string {
		$childAppId = $this->finalizeInChild($appPath, $info, $enabled, $sourceId);
		if ($childAppId !== null) {
			$this->rememberInstall($childAppId, $this->installedVersionOf($info), $enabled);

			return $childAppId;
		}

		$appId = self::appIdOf($info);

		// Lazy registration must run before autoload + migrations so app-registered
		// event listeners are wired up when migrations dispatch events.
		Server::get(Coordinator::class)->runLazyRegistration($appId);

		// \OC_App::registerAutoloading() is gone in Nextcloud 35; AppAutoloader
		// picks whichever registrar this server has.
		AppAutoloader::register($appId, $appPath, $this->appManager, $info);

		$this->runMigrations($info, $output);
		$this->addBackgroundJobs($info);
		$this->runInstallScript($appPath, $appId);
		self::executeRepairSteps($appId, self::repairStepsOf($info, 'install'));

		$installedVersion = $this->installedVersionOf($info);
		/**
		 * setAppValue, not setValueString, for the keys core owns.
		 *
		 * Since Nextcloud 29 every appconfig row carries a type, and core only
		 * tolerates a write whose type differs from the stored one when the
		 * stored type is VALUE_MIXED. Core writes installed_version, enabled
		 * and its own remote_/public_ routes through the untyped
		 * IConfig::setAppValue(), so they land as MIXED. Writing them as
		 * VALUE_STRING here left a typed row that core could no longer update,
		 * and the next `occ app:enable` died with
		 *
		 *   conflict between new type (mixed) and old type (string)
		 *
		 * That made every app installed through Versioniq impossible to
		 * enable, which is most of the point of the app. Config we own stays
		 * on the typed API; only core's keys use core's semantics.
		 */
		/** @psalm-suppress DeprecatedMethod Deliberate — see the block comment above. */
		$this->configs->config->setAppValue($appId, 'installed_version', $installedVersion);
		/** @psalm-suppress DeprecatedMethod Deliberate — see the block comment above. */
		$this->configs->config->setAppValue($appId, 'enabled', $enabled);
		$this->registerRoutes($info);

		$this->persistAppTypes($appId);
		$this->appManager->clearAppsCache();

		// Reached only on success: every earlier step throws on failure and
		// aborts before this line, so a failed or reverted install never
		// touches the record — see "Last-known-good version record".
		$this->lkgStore->set($appId, new Lkg(
			$installedVersion,
			$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
			$sourceId,
		));
		$this->rememberInstall($appId, $installedVersion, $enabled);

		return $appId;
	}

	/**
	 * Stores the installed version as seen, so this install is never
	 * recorded a second time as a change made outside Versioniq. Reached only
	 * after a successful install.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	private function rememberInstall(string $appId, string $installedVersion, string $enabled): void {
		try {
			$this->seenState?->rememberApp($appId, $installedVersion, $enabled !== 'no');
		} catch (\Throwable $error) {
			$this->logger->warning('InstallFinalizer: could not update the seen state', ['appId' => $appId, 'message' => $error->getMessage()]);
		}
	}

	/**
	 * Hands an update to the child finalizer; the app id when the child
	 * finalized, null when this process has to. Without a child finalizer
	 * (built by hand, or Nextcloud's container could not build one and fell
	 * back to the parameter's default) an update logs a warning first, so the
	 * #611 fix is not switched off without a sound.
	 *
	 * @param array<string, mixed> $info
	 * @throws Exception When the finalize failed in the child.
	 * @spec openspec/specs/version-management/spec.md
	 */
	private function finalizeInChild(string $appPath, array $info, string $enabled, string $sourceId): ?string {
		if ($this->childFinalizer === null) {
			$appId = self::appIdOf($info);
			if ($appId !== '' && $this->configs->appConfig->getValueString($appId, 'installed_version', '') !== '') {
				$this->logger->warning('versioniq: no child finalizer available; finalizing the update of {app} in-process, where repair steps of the new version may fail against the previous version\'s registrations (#611).', ['app' => $appId]);
			}

			return null;
		}

		return $this->childFinalizer->applies($info) ? $this->childFinalizer->run($appPath, $info, $enabled, $sourceId) : null;
	}

	/**
	 * @param array<string, mixed> $info
	 */
	private static function appIdOf(array $info): string {
		return (string)($info['id'] ?? '');
	}

	/**
	 * Runs the schema migrations, with the pre- and post-migration repair
	 * steps around them on an upgrade (an app with an installed version).
	 *
	 * @param array<string, mixed> $info
	 */
	private function runMigrations(array $info, ?IOutput $output): void {
		$appId = self::appIdOf($info);
		$previousVersion = $this->configs->appConfig->getValueString($appId, 'installed_version', '');
		$migrationService = new MigrationService($appId, Server::get(Connection::class));
		if ($output instanceof IOutput) {
			$migrationService->setOutput($output);
		}

		$upgrade = $previousVersion !== '';
		if ($upgrade) {
			self::executeRepairSteps($appId, self::repairStepsOf($info, 'pre-migration'));
		}
		$migrationService->migrate('latest', !$upgrade);
		if ($upgrade) {
			self::executeRepairSteps($appId, self::repairStepsOf($info, 'post-migration'));
		}
	}

	/**
	 * The repair steps info.xml declares for one phase.
	 *
	 * @param array<string, mixed> $info
	 * @return array<array-key, mixed>
	 */
	private static function repairStepsOf(array $info, string $phase): array {
		$repairSteps = (array)($info['repair-steps'] ?? []);

		return (array)($repairSteps[$phase] ?? []);
	}

	/**
	 * @param array<array-key, mixed> $steps
	 */
	private static function executeRepairSteps(string $appId, array $steps): void {
		if ($steps !== []) {
			\OC_App::executeRepairSteps($appId, $steps);
		}
	}

	/**
	 * @param array<string, mixed> $info
	 */
	private function addBackgroundJobs(array $info): void {
		/** @var list<string> $backgroundJobs */
		$backgroundJobs = (array)($info['background-jobs'] ?? []);
		foreach ($backgroundJobs as $job) {
			/** @var class-string<IJob> $job */
			$this->jobList->add($job);
		}
	}

	private function runInstallScript(string $appPath, string $appId): void {
		$appInstallScriptPath = $appPath . '/appinfo/install.php';
		if (!file_exists($appInstallScriptPath)) {
			return;
		}
		$this->logger->warning('Using an appinfo/install.php file is deprecated. Application "{app}" still uses one.', [
			'app' => $appId,
		]);
		self::includeAppScript($appInstallScriptPath);
	}

	/**
	 * The version info.xml declares, or the one the app manager reads when
	 * info.xml has none.
	 *
	 * @param array<string, mixed> $info
	 */
	private function installedVersionOf(array $info): string {
		$infoVersion = (string)($info['version'] ?? '');

		return $infoVersion !== ''
			? $infoVersion
			: $this->appManager->getAppVersion(self::appIdOf($info), false);
	}

	/**
	 * Registers the app's remote and public routes as core's `remote_*` and
	 * `public_*` keys, written untyped like core's own (see finalize()).
	 *
	 * @param array<string, mixed> $info
	 */
	private function registerRoutes(array $info): void {
		$appId = self::appIdOf($info);
		foreach (['remote', 'public'] as $kind) {
			/** @var array<string, string> $routes */
			$routes = (array)($info[$kind] ?? []);
			foreach ($routes as $name => $path) {
				/** @psalm-suppress DeprecatedMethod Deliberate — see the block comment in finalize(). */
				$this->configs->config->setAppValue('core', $kind . '_' . $name, $appId . '/' . $path);
			}
		}
	}

	/**
	 * Stores the app's declared `<types>` as the comma-joined `types` app-config
	 * value that `AppManager` reads (`AppManager::getAppTypes()` loads the
	 * `types` config key across all apps). This replicates the removed
	 * `OC_App::setAppTypes()` — gone from Nextcloud 34 — using public API. Without
	 * it, an app declaring e.g. `<types>filesystem</types>` is not recognised as
	 * that type after an install through this app, and the finalize phase would
	 * fatal on the missing static method (which every install path shares).
	 */
	private function persistAppTypes(string $appId): void {
		$appInfo = $this->appManager->getAppInfo($appId);
		$types = '';
		if (is_array($appInfo) && isset($appInfo['types']) && is_array($appInfo['types'])) {
			$types = implode(',', array_map('strval', $appInfo['types']));
		}
		// Core-owned key as well: OC_App writes app types untyped, so a typed
		// row here would collide the same way installed_version did.
		/** @psalm-suppress DeprecatedMethod Deliberate — core owns this key and writes it untyped. */
		$this->configs->config->setAppValue($appId, 'types', $types);
	}

	private static function includeAppScript(string $script): void {
		if (file_exists($script)) {
			include $script;
		}
	}
}
