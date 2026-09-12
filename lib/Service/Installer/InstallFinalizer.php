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
use OCA\Versioniq\Service\Lkg\Lkg;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use Psr\Container\ContainerInterface;
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
		private IAppConfig $appConfig,
		/**
		 * Only for the handful of keys Nextcloud core owns and writes itself.
		 * IConfig::setAppValue() is deprecated, but it is the sole public way
		 * to store a value untyped (VALUE_MIXED), and matching core's own type
		 * is the entire point. See the comment on the writes below.
		 */
		private IConfig $config,
		private IAppManager $appManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private LkgStore $lkgStore,
		private ITimeFactory $timeFactory,
		/**
		 * The app container, used to resolve the two private core classes this
		 * finalizer needs at call time: the bootstrap Coordinator and the raw
		 * DB Connection the MigrationService takes. Neither can be a plain
		 * constructor type-hint here: the Coordinator is what registers app
		 * services in the first place, so depending on it eagerly invites the
		 * bootstrap cycle. Resolving through the injected container keeps the
		 * lookup lazy and lets a test hand in its own.
		 */
		private ContainerInterface $container,
	) {
	}

	/**
	 * Runs migrations, repair steps, job + route registration, and version/enabled writes after extraction;
	 * see "Install Specific Version" ("any database migrations for the new version MUST be triggered") and
	 * "Last-known-good version record" (the last statement here — reached only on success — is the single
	 * choke point that writes `lkg.{appId}` for both installers).
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` for the just-extracted version.
	 * @param string $sourceId The source binding id (e.g. `appstore`, `github:owner/repo`) this version was
	 *                         installed from, recorded alongside the last-known-good version.
	 * @throws Exception
	 */
	public function finalize(string $appPath, array $info, string $enabled, ?IOutput $output = null, string $sourceId = 'appstore'): string {
		$appId = (string)($info['id'] ?? '');

		// Lazy registration must run before autoload + migrations so app-registered
		// event listeners are wired up when migrations dispatch events.
		/** @var Coordinator $coordinator */
		$coordinator = $this->container->get(Coordinator::class);
		$coordinator->runLazyRegistration($appId);

		\OC_App::registerAutoloading($appId, $appPath);

		$previousVersion = $this->appConfig->getValueString($appId, 'installed_version', '');
		/** @var Connection $connection */
		$connection = $this->container->get(Connection::class);
		$migrationService = new MigrationService($appId, $connection);
		if ($output instanceof IOutput) {
			$migrationService->setOutput($output);
		}

		$repairSteps = (array)($info['repair-steps'] ?? []);

		$preMigration = (array)($repairSteps['pre-migration'] ?? []);
		if ($previousVersion !== '' && $preMigration !== []) {
			\OC_App::executeRepairSteps($appId, $preMigration);
		}

		$migrationService->migrate('latest', $previousVersion === '');

		$postMigration = (array)($repairSteps['post-migration'] ?? []);
		if ($previousVersion !== '' && $postMigration !== []) {
			\OC_App::executeRepairSteps($appId, $postMigration);
		}

		/** @var list<string> $backgroundJobs */
		$backgroundJobs = (array)($info['background-jobs'] ?? []);
		foreach ($backgroundJobs as $job) {
			/** @var class-string<IJob> $job */
			$this->jobList->add($job);
		}

		$appInstallScriptPath = $appPath . '/appinfo/install.php';
		if (file_exists($appInstallScriptPath)) {
			$this->logger->warning('Using an appinfo/install.php file is deprecated. Application "{app}" still uses one.', [
				'app' => $appId,
			]);
			self::includeAppScript($appInstallScriptPath);
		}

		$installStep = (array)($repairSteps['install'] ?? []);
		if ($installStep !== []) {
			\OC_App::executeRepairSteps($appId, $installStep);
		}

		$infoVersion = (string)($info['version'] ?? '');
		$installedVersion = $infoVersion !== ''
			? $infoVersion
			: $this->appManager->getAppVersion($appId, false);
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
		$this->config->setAppValue($appId, 'installed_version', $installedVersion);
		/** @psalm-suppress DeprecatedMethod Deliberate — see the block comment above. */
		$this->config->setAppValue($appId, 'enabled', $enabled);

		/** @var array<string, string> $remote */
		$remote = (array)($info['remote'] ?? []);
		foreach ($remote as $name => $path) {
			/** @psalm-suppress DeprecatedMethod Deliberate — see the block comment above. */
			$this->config->setAppValue('core', 'remote_' . $name, $appId . '/' . $path);
		}
		/** @var array<string, string> $public */
		$public = (array)($info['public'] ?? []);
		foreach ($public as $name => $path) {
			/** @psalm-suppress DeprecatedMethod Deliberate — see the block comment above. */
			$this->config->setAppValue('core', 'public_' . $name, $appId . '/' . $path);
		}

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

		return $appId;
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
		$this->config->setAppValue($appId, 'types', $types);
	}

	private static function includeAppScript(string $script): void {
		if (file_exists($script)) {
			include $script;
		}
	}
}
