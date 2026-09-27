<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service;

use Exception;
use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\EnvironmentCheck;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Coordinates the version-management flow:
 *   - lists installed apps
 *   - resolves the active source binding for an app (sticky GitHub or App Store fallback)
 *   - delegates version listing to the matching source driver
 *   - dispatches install to either the signed installer (App Store) or the
 *     external installer (GitHub releases), then writes the binding on success.
 *
 * @psalm-api
 */
class InstallerService {
	/** Server-side changelog size cap; see "Version listings carry release notes". */
	private const CHANGELOG_MAX_BYTES = 8192;
	private const CHANGELOG_TRUNCATION_MARKER = ' …[truncated]';

	public const OVERRIDE_PIN_REPIN = 'repin';
	public const OVERRIDE_PIN_UNPIN = 'unpin';

	public function __construct(
		private IAppManager $appManager,
		private IConfig $config,
		private IAppConfig $appConfig,
		private SourceRegistry $sourceRegistry,
		private SourceBindingStore $bindingStore,
		private TrustedSourceList $trustedSources,
		private SelectedReleaseInstallerService $signedInstaller,
		private ExternalReleaseInstallerService $externalInstaller,
		private FailureClassifier $failureClassifier,
		private EnvironmentCheck $environmentCheck,
		private PinStore $pinStore,
		private IUserSession $userSession,
		private ITimeFactory $timeFactory,
		private LkgStore $lkgStore,
		private ArtifactCache $artifactCache,
	) {
	}

	/**
	 * Returns the manageable apps enriched with metadata for frontend cards:
	 * enabled apps, installed-but-disabled apps and apps bound to a source but
	 * not installed yet, each with its `state` (`enabled`, `disabled`,
	 * `notInstalled`), including the last-known-good version record; see
	 * "List Installed Apps" and
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @return list<array{id:string,label:string,description:string,summary:string,preview:string,isCore:bool,isShipped:bool,boundSourceId:?string,manageable:bool,warning:?string,installedVersion:?string,state:'enabled'|'disabled'|'notInstalled',lkg:?array{version:string,recordedAt:string,sourceId:?string}}>
	 */
	public function getInstalledApps(): array {
		// Enabled apps, installed-but-disabled apps (Nextcloud keeps their
		// installed version) and apps bound to a source but not installed yet,
		// so every one of them can be picked and installed from the page; see
		// "List Installed Apps".
		$enabledApps = $this->appManager->getEnabledApps();
		$installedVersions = $this->appManager->getAppInstalledVersions(false);
		$installedApps = array_values(array_unique(array_filter(
			array_merge($enabledApps, array_keys($installedVersions), $this->bindingStore->listBoundAppIds()),
			fn (string $appId): bool => !$this->isSelfManagedApp($appId)
		)));
		sort($installedApps);
		$alwaysEnabledApps = $this->appManager->getAlwaysEnabledApps();
		$appList = [];
		foreach ((new \OC_App())->listAllApps() as $app) {
			if (!isset($app['id']) || !is_string($app['id'])) {
				continue;
			}
			$appList[$app['id']] = $app;
		}

		$bindingStore = $this->bindingStore;
		$appManager = $this->appManager;
		$environmentCheck = $this->environmentCheck;
		$lkgStore = $this->lkgStore;

		return array_map(
			static function (string $appId) use ($appList, $alwaysEnabledApps, $bindingStore, $appManager, $environmentCheck, $lkgStore, $enabledApps, $installedVersions): array {
				$app = $appList[$appId] ?? [];
				$name = isset($app['name']) && is_string($app['name']) && trim($app['name']) !== ''
					? trim($app['name'])
					: $appId;
				$description = isset($app['description']) && is_string($app['description']) ? trim($app['description']) : '';
				$summary = isset($app['summary']) && is_string($app['summary']) ? trim($app['summary']) : '';
				$preview = isset($app['preview']) && is_string($app['preview']) ? trim($app['preview']) : '';
				$binding = $bindingStore->get($appId);

				// Proactively flag apps whose folder cannot be managed here
				// (e.g. a bind-mounted dev checkout that is not writable).
				$env = ['manageable' => true, 'warning' => null];
				try {
					$env = $environmentCheck->inspect($appManager->getAppPath($appId));
				} catch (\Throwable) {
					// Path unknown — leave defaults; install-time guard still applies.
				}

				if (in_array($appId, $enabledApps, true)) {
					$state = 'enabled';
				} elseif (isset($installedVersions[$appId])) {
					$state = 'disabled';
				} else {
					$state = 'notInstalled';
				}

				$installedVersion = '';
				if ($state !== 'notInstalled') {
					try {
						$installedVersion = $appManager->getAppVersion($appId, false);
					} catch (Exception) {
						$installedVersion = '';
					}
					if ($installedVersion === '') {
						$installedVersion = $installedVersions[$appId] ?? '';
					}
				}

				return [
					'id' => $appId,
					'label' => $name,
					'description' => $description,
					'summary' => $summary,
					'preview' => $preview,
					'isCore' => in_array($appId, $alwaysEnabledApps, true),
					'isShipped' => $appManager->isShipped($appId),
					'boundSourceId' => $binding?->getId(),
					'manageable' => $env['manageable'],
					'warning' => $env['warning'],
					'installedVersion' => $installedVersion === '' ? null : $installedVersion,
					'state' => $state,
					'lkg' => $lkgStore->get($appId)?->toArray(),
				];
			},
			$installedApps
		);
	}

	/**
	 * Resolves the active source and lists versions for an app; see "Fetch Available Versions", "Explicit source
	 * override" and "Version listings carry release notes".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return array{installedVersion: ?string, availableVersions: list<array{version:string, changelog:?string, recordedSha:?string, cachedOffline:bool}>, versions: list<array{version:string, changelog:?string, recordedSha:?string, cachedOffline:bool}>, source: string, sourceId: string, statusCode: int, hasError: bool, error?: string}
	 */
	public function getAppVersions(string $appId, ?string $sourceOverride = null): array {
		$appId = trim($appId);
		if ($appId === '') {
			return $this->errorEnvelope('Missing app id.', Http::STATUS_BAD_REQUEST);
		}
		if (!$this->isManageableApp($appId)) {
			return $this->errorEnvelope(
				$this->isCoreProtectedApp($appId)
					? 'This core app cannot be managed from Versioniq.'
					: 'This app cannot be managed from Versioniq.',
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$binding = $this->resolveBinding($appId, $sourceOverride);
		} catch (InvalidArgumentException $error) {
			return $this->errorEnvelope($error->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (UntrustedSourceException $error) {
			return $this->errorEnvelope($error->getMessage(), Http::STATUS_FORBIDDEN);
		}

		$source = $this->sourceRegistry->get($binding);
		$result = $source->listVersions($appId, $binding);

		// Shipped apps (bundled with the server release) are never published to
		// the App Store, so a store miss is expected — explain it instead of
		// surfacing a generic "not available" error.
		if ($result['error'] === 'App is not available in the Nextcloud App Store.'
			&& $this->appManager->isShipped($appId)
		) {
			$result['error'] = 'This app ships with Nextcloud itself: its version follows the server release and it is not distributed via the App Store. Bind a forge source to manage it from a repository instead.';
		}

		$installedVersion = null;
		try {
			$installed = $this->appManager->getAppVersion($appId);
			if ($installed !== '') {
				$installedVersion = $installed;
			}
		} catch (Exception) {
			$installedVersion = null;
		}

		$versions = $this->applyChangelogTruncation($result['versions']);
		$versions = $this->applyRecordedSha($versions, $binding);
		$versions = $this->applyCachedOffline($versions, $appId);

		$envelope = [
			'installedVersion' => $installedVersion,
			'availableVersions' => $versions,
			'versions' => $versions,
			'source' => $binding->kind,
			'sourceId' => $binding->getId(),
			'statusCode' => Http::STATUS_OK,
			'hasError' => $result['error'] !== null && $result['versions'] === [],
		];
		if ($result['error'] !== null) {
			$envelope['error'] = $result['error'];
		}

		return $envelope;
	}

	/**
	 * Truncates each version entry's changelog to at most `CHANGELOG_MAX_BYTES`
	 * bytes (UTF-8-safe), appending a truncation marker when it was cut. This
	 * is the single shared code path both source kinds' envelopes pass
	 * through, so truncation behaviour is identical regardless of origin.
	 *
	 * @spec openspec/specs/changelog-visibility/spec.md
	 * @param list<array{version:string, changelog?:?string, serverCompatible?:?bool}> $versions
	 * @return list<array{version:string, changelog:?string, serverCompatible?:?bool}>
	 */
	private function applyChangelogTruncation(array $versions): array {
		return array_map(
			function (array $entry): array {
				/** @var mixed $changelog */
				$changelog = $entry['changelog'] ?? null;
				$entry['changelog'] = is_string($changelog) ? $this->truncateChangelog($changelog) : null;

				return $entry;
			},
			$versions
		);
	}

	private function truncateChangelog(string $changelog): string {
		if (strlen($changelog) <= self::CHANGELOG_MAX_BYTES) {
			return $changelog;
		}

		return mb_strcut($changelog, 0, self::CHANGELOG_MAX_BYTES, 'UTF-8') . self::CHANGELOG_TRUNCATION_MARKER;
	}

	/**
	 * Attaches the binding's recorded SHA-256 (if any) to each version entry
	 * so the picker can badge versions with a first-install checksum on
	 * record; see "Recorded digests are binding-scoped and surfaced".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @param list<array{version:string, changelog:?string, serverCompatible?:?bool}> $versions
	 * @return list<array{version:string, changelog:?string, serverCompatible?:?bool, recordedSha:?string}>
	 */
	private function applyRecordedSha(array $versions, SourceBinding $binding): array {
		return array_map(
			static function (array $entry) use ($binding): array {
				$entry['recordedSha'] = $binding->getRecordedSha($entry['version']);

				return $entry;
			},
			$versions
		);
	}

	/**
	 * Stamps `cachedOffline` on each version entry from a single cached-version
	 * query for `$appId` (no per-version IO); see "Cache visibility and
	 * management".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @param list<array<string, mixed>> $versions
	 * @return list<array<string, mixed>>
	 */
	private function applyCachedOffline(array $versions, string $appId): array {
		$cachedVersions = array_flip($this->artifactCache->cachedVersionsFor($appId));

		return array_map(
			static function (array $entry) use ($cachedVersions): array {
				$entry['cachedOffline'] = isset($cachedVersions[$entry['version']]);

				return $entry;
			},
			$versions
		);
	}

	/**
	 * Installs a target version via the matching installer and persists the
	 * binding on success; see "Install Specific Version", "Source binding",
	 * and "Pins are enforced on Versioniq's own install path".
	 *
	 * `$overridePin` is `null` (no override requested), `repin`, or `unpin` —
	 * any other value is rejected with 400 by the caller before this method
	 * is reached in the normal flow, but is defensively rejected here too.
	 * `$pinRequested` pins the resulting version after a successful install
	 * when the app was not already pinned (atomic install-then-pin).
	 *
	 * `$acceptNewSha` bypasses, for this one request, a recorded-SHA-256
	 * mismatch on an external install and replaces the recorded digest on
	 * success — see "Recorded SHA-256 enforced on reinstall". Ignored for
	 * App Store (signed) installs, which do not record digests.
	 *
	 * `$allowDowngrade` acknowledges a downgrade (target version older than
	 * installed); without it a real (non-dry-run) downgrade is refused with
	 * a structured 409 before any download — see "Server-side downgrade
	 * guard". Dry-run requests evaluate the same condition but are never
	 * blocked by it.
	 *
	 * `$dryRun` is the explicit dry-run trigger, independent of
	 * `$includeDebug` (which now controls diagnostic verbosity only); see
	 * MODIFIED "Debug Mode". `null` (the default) preserves the legacy
	 * behavior of every caller that predates this parameter: falls back to
	 * `$includeDebug`, so `debug=1` alone still implies a dry-run.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @spec openspec/specs/cli-commands/spec.md
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	public function installAppVersion(
		string $appId,
		string $targetVersion,
		bool $includeDebug,
		?string $sourceOverride = null,
		?string $overridePin = null,
		bool $pinRequested = false,
		bool $acceptNewSha = false,
		bool $allowDowngrade = false,
		?bool $dryRun = null,
	): array {
		// Independent of $includeDebug (deprecation-preserving fallback only
		// when the caller does not pass $dryRun at all); see MODIFIED "Debug
		// Mode" — "Legacy behavior preserved".
		$dryRun = $dryRun ?? $includeDebug;
		$appId = trim($appId);
		$targetVersion = trim($targetVersion);
		if ($appId === '' || $targetVersion === '') {
			return [
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'payload' => ['message' => 'Missing app id or version.'],
			];
		}
		if (!$this->isManageableApp($appId)) {
			return [
				'statusCode' => Http::STATUS_FORBIDDEN,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => $this->isCoreProtectedApp($appId)
						? 'This core app cannot be installed or updated from Versioniq.'
						: 'This app cannot be installed or updated from Versioniq.',
				],
			];
		}
		if ($overridePin !== null && $overridePin !== self::OVERRIDE_PIN_REPIN && $overridePin !== self::OVERRIDE_PIN_UNPIN) {
			return [
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => 'overridePin must be "repin" or "unpin".',
				],
			];
		}

		try {
			$binding = $this->resolveBinding($appId, $sourceOverride);
			$this->trustedSources->assertBindingAllowed($binding);
		} catch (InvalidArgumentException $error) {
			return ['statusCode' => Http::STATUS_BAD_REQUEST, 'payload' => ['message' => $error->getMessage()]];
		} catch (UntrustedSourceException $error) {
			return ['statusCode' => Http::STATUS_FORBIDDEN, 'payload' => ['message' => $error->getMessage()]];
		}

		try {
			$this->appManager->clearAppsCache();
			$installedVersion = $this->appManager->getAppVersion($appId, false);
		} catch (Exception) {
			$installedVersion = '';
		}

		// Server-side downgrade guard: refuse a real (non-dry-run) downgrade
		// before any download unless explicitly acknowledged — see "Server-side
		// downgrade guard". Dry-run requests evaluate this same condition but
		// are never blocked by it (they report via updateType/orphanedMigrations
		// instead) — see "Dry-run requests MUST evaluate and report the guard
		// without requiring the flag".
		$isDowngradeRequest = $installedVersion !== '' && version_compare($targetVersion, $installedVersion, '<');
		if ($isDowngradeRequest && !$dryRun && !$allowDowngrade) {
			$category = FailureClassifier::CATEGORY_DOWNGRADE_GUARD;

			// $dryRun is false here (the guard above only fires when
			// !$dryRun), so this is a real install refusal; $includeDebug is
			// independent and may still be true, but there is no debug
			// branch to attach — nothing was attempted.
			return [
				'statusCode' => $this->failureClassifier->httpStatusFor($category),
				'payload' => [
					'appId' => $appId,
					'fromVersion' => $installedVersion,
					'toVersion' => $targetVersion,
					'message' => $this->failureClassifier->messageFor($category),
					'category' => $category,
					'hint' => $this->failureClassifier->downgradeGuardHint($installedVersion, $targetVersion),
					'installStatus' => 'failed',
					'sourceId' => $binding->getId(),
				],
			];
		}

		// Pin guard: Versioniq's own install path refuses to overwrite a
		// pinned app without an explicit override — see "Pins are enforced on
		// Versioniq's own install path". Reinstalling the pinned version
		// itself is never blocked (no drift, nothing to override).
		$pin = $this->pinStore->get($appId);
		$isOverridingPin = $pin !== null && $targetVersion !== $pin->version;
		if ($isOverridingPin && $overridePin === null) {
			return [
				'statusCode' => Http::STATUS_CONFLICT,
				'payload' => [
					'appId' => $appId,
					'fromVersion' => $installedVersion === '' ? null : $installedVersion,
					'toVersion' => $targetVersion,
					'message' => sprintf('This app is pinned to version %s. Pass overridePin=repin or overridePin=unpin to proceed.', $pin->version),
					'category' => 'pinned',
					'pinnedVersion' => $pin->version,
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}

		if ($installedVersion !== '' && version_compare($targetVersion, $installedVersion, '=')) {
			// Reinstalling the currently-installed pinned version with a stale
			// drift marker (e.g. the app was separately restored to the pinned
			// version) clears that marker — see "Re-pin reinstalls the pinned
			// version".
			if ($pin !== null && $targetVersion === $pin->version && $pin->hasDrifted()) {
				$this->pinStore->set($appId, new Pin($pin->version, $pin->pinnedBy, $pin->pinnedAt, $pin->reason));
			}

			return [
				'statusCode' => Http::STATUS_OK,
				'payload' => [
					'appId' => $appId,
					'fromVersion' => $installedVersion,
					'toVersion' => $targetVersion,
					'message' => 'App already has this version installed.',
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}

		// Pre-flight: replacing an existing app renames its folder, which needs a
		// writable parent directory. A bind-mounted dev checkout (owned by another
		// uid) is not writable by the web-server user, so the install can never
		// succeed — fail fast before downloading anything.
		try {
			$existingPath = $this->appManager->getAppPath($appId);
		} catch (Exception) {
			$existingPath = null;
		}
		if ($existingPath !== null && !$this->environmentCheck->isDestinationWritable($existingPath)) {
			$category = FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION;

			return [
				'statusCode' => $this->failureClassifier->httpStatusFor($category),
				'payload' => [
					'appId' => $appId,
					'fromVersion' => $installedVersion === '' ? null : $installedVersion,
					'toVersion' => $targetVersion,
					'message' => $this->failureClassifier->messageFor($category),
					'category' => $category,
					'stage' => FailureClassifier::STAGE_REQUESTED,
					'hint' => $this->failureClassifier->hintFor($category),
					'installStatus' => 'failed',
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}

		$source = $this->sourceRegistry->get($binding);
		$release = $source->resolveRelease($appId, $targetVersion, $binding);
		if ($release === null) {
			return [
				'statusCode' => Http::STATUS_NOT_FOUND,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => 'Requested version not found in source metadata.',
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}
		if (isset($release['error']) && is_string($release['error'])) {
			return [
				'statusCode' => Http::STATUS_BAD_REQUEST,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => $release['error'],
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		}

		$maintenanceWasSet = false;
		// $dryRun was already resolved (independent of $includeDebug) at the
		// top of this method — see MODIFIED "Debug Mode".
		try {
			// A dry run changes nothing, so it never locks users out (#427).
			if (!$dryRun && !$this->config->getSystemValueBool('maintenance', false)) {
				$maintenanceWasSet = true;
				$this->config->setSystemValue('maintenance', true);
			}

			$recordedShaMatched = null;
			if ($source->getInstallerKind() === \OCA\Versioniq\Service\Source\SourceInterface::INSTALLER_SIGNED) {
				$result = $this->signedInstaller->installFromSelectedRelease($appId, $release, $dryRun);
				$integrityWarning = null;
			} else {
				$result = $this->externalInstaller->installFromExternalRelease($appId, $targetVersion, $release, $binding, $dryRun, $acceptNewSha);
				$integrityWarning = $result['integrityWarning'] ?? null;
				$recordedShaMatched = $result['recordedShaMatched'] ?? null;
				// The external installer may have recorded/replaced a SHA-256 on
				// the binding — persist that updated binding, not the pre-install
				// one, so the digest is not lost; see "SHA-256 recorded on first
				// successful external install".
				$binding = $result['binding'];
			}

			if (!$dryRun) {
				$this->appManager->clearAppsCache();
				$this->bindingStore->set($appId, $binding);
			}

			$this->appManager->clearAppsCache();
			$appVersion = null;
			try {
				$appPath = $this->appManager->getAppPath($appId, true);
				$appInfo = $this->appManager->getAppInfoByPath($appPath . '/appinfo/info.xml');
				if (is_array($appInfo) && isset($appInfo['version']) && is_string($appInfo['version'])) {
					$appVersion = $appInfo['version'];
				}
			} catch (Exception) {
				$appVersion = null;
			}
			if ($appVersion === null) {
				$appVersion = $this->appManager->getAppVersion($appId, false);
			}
			$configuredVersion = $this->appConfig->getValueString($appId, 'installed_version', $appVersion);
			if ($configuredVersion !== '') {
				$appVersion = $configuredVersion;
			}

			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $dryRun ? $targetVersion : $appVersion,
				'installedVersion' => $appVersion,
				'updateType' => $this->classifyUpdateType($installedVersion, $appVersion, $dryRun),
				'message' => $this->classifyMessage($installedVersion, $appVersion, $dryRun),
				'dryRun' => $dryRun,
				'installStatus' => $result['status'] ?? 'unknown',
				'sourceId' => $binding->getId(),
				// See "Cached fallback with full re-verification" — the outcome
				// states when the download step fell back to a locally cached
				// artifact instead of the source.
				'servedFromCache' => (bool)($result['servedFromCache'] ?? false),
			];
			if ($integrityWarning !== null) {
				$payload['integrityWarning'] = $integrityWarning;
			}
			if ($recordedShaMatched !== null) {
				// See "Recorded SHA-256 enforced on reinstall" — Scenario
				// "Matching digest proceeds": the response indicates the
				// artifact matched the first-install checksum.
				$payload['recordedShaMatched'] = $recordedShaMatched;
			}
			if (array_key_exists('orphanedMigrations', $result)) {
				// Present only for a downgrade (acknowledged or dry-run); see
				// "Migration diff on downgrade". `null` means the diff could not
				// be computed (degrades to a generic warning), `[]` means no
				// schema steps differ. The signed installer's return type is
				// loosely typed (array<string, mixed>), so the value is
				// explicitly annotated here rather than inferred.
				/** @var list<string>|null $orphanedMigrations */
				$orphanedMigrations = $result['orphanedMigrations'];
				$payload['orphanedMigrations'] = $orphanedMigrations;
			}
			if ($includeDebug) {
				$payload['debug'] = (array)($result['debug'] ?? []);
			}

			// Pin state changes only after a real (non-dry-run) install success;
			// see "Pins are enforced on Versioniq's own install path". Adjusting
			// the pin here — inside this same request, immediately after the
			// filesystem swap and before returning — is what keeps a subsequent
			// drift check from misreading our own override as drift.
			if (!$dryRun) {
				// $isOverridingPin implies $pin !== null and, having reached this
				// point, $overridePin !== null (the guard above already returned
				// 409 otherwise) — so $overridePin is exactly 'repin' or 'unpin'.
				if ($isOverridingPin) {
					if ($overridePin === self::OVERRIDE_PIN_REPIN) {
						$this->pinStore->set($appId, new Pin($appVersion, $this->currentActorUid(), $this->nowIso(), $pin->reason));
					} else {
						$this->pinStore->clear($appId, $this->currentActorUid());
					}
				} elseif ($pin === null && $pinRequested) {
					$this->pinStore->set($appId, new Pin($appVersion, $this->currentActorUid(), $this->nowIso()));
				} elseif ($pin !== null && $targetVersion === $pin->version && $pin->hasDrifted()) {
					// Re-pin after drift (Re-pin button reinstalls the pinned
					// version) — clear the drift markers; see "Re-pin reinstalls
					// the pinned version".
					$this->pinStore->set($appId, new Pin($pin->version, $pin->pinnedBy, $pin->pinnedAt, $pin->reason));
				}
			}

			return ['statusCode' => Http::STATUS_OK, 'payload' => $payload];
		} catch (UntrustedSourceException $error) {
			return [
				'statusCode' => Http::STATUS_FORBIDDEN,
				'payload' => [
					'appId' => $appId,
					'toVersion' => $targetVersion,
					'message' => $error->getMessage(),
					'sourceId' => $binding->getId(),
				] + ($includeDebug ? ['debug' => []] : []),
			];
		} catch (ShaMismatchException $error) {
			// Recorded-digest mismatch: no filesystem change happened (thrown
			// before extraction/backup). Machine-readable `code` lets the
			// frontend render the explicit "accept new checksum" escape hatch;
			// see "Recorded SHA-256 enforced on reinstall".
			$classification = $this->failureClassifier->classify($error, FailureClassifier::STAGE_CHECKSUM, FailureClassifier::CATEGORY_SHA_MISMATCH);
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $error->getMessage(),
				'category' => $classification['category'],
				'code' => 'sha_mismatch',
				'stage' => FailureClassifier::STAGE_CHECKSUM,
				'hint' => $classification['hint'],
				'installStatus' => 'failed',
				'sourceId' => $binding->getId(),
				'expectedSha' => $error->expectedSha,
				'actualSha' => $error->actualSha,
			];
			if ($includeDebug) {
				$payload['debug'] = $this->installerDebugLog($source);
			}

			return ['statusCode' => $classification['statusCode'], 'payload' => $payload];
		} catch (InstallFailure $failure) {
			// The installer already handled filesystem recovery; report the
			// honest outcome (reverted / installed-but-broken) instead of a 500.
			$debugLog = $this->installerDebugLog($source);
			$isBroken = $failure->getOutcome() === InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN;
			$category = $isBroken
				? FailureClassifier::CATEGORY_FINALIZE
				: $this->failureClassifier->categoryFor($failure, $failure->getStage());
			$hint = $isBroken
				? $this->failureClassifier->finalizeHint($failure->getRestoreState())
				: $this->failureClassifier->revertedHint();
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $failure->getMessage(),
				'category' => $category,
				'stage' => $failure->getStage(),
				'hint' => $hint,
				'installStatus' => $failure->getOutcome(),
				'sourceId' => $binding->getId(),
			];
			if ($includeDebug) {
				$payload['debug'] = $debugLog;
			}

			return ['statusCode' => $this->failureClassifier->httpStatusFor($category), 'payload' => $payload];
		} catch (Exception $error) {
			// Classified failure: attach stage/category/hint regardless of debug,
			// and derive the HTTP status from the category (no blanket 500).
			$debugLog = $this->installerDebugLog($source);
			$lastStage = $this->lastStageOf($debugLog);
			$classification = $this->failureClassifier->classify($error, $lastStage);
			$payload = [
				'appId' => $appId,
				'fromVersion' => $installedVersion === '' ? null : $installedVersion,
				'toVersion' => $targetVersion,
				'message' => $error->getMessage(),
				'category' => $classification['category'],
				'stage' => $lastStage,
				'hint' => $classification['hint'],
				'installStatus' => 'failed',
				'sourceId' => $binding->getId(),
			];
			if ($includeDebug) {
				$payload['debug'] = $debugLog;
			}

			return ['statusCode' => $classification['statusCode'], 'payload' => $payload];
		} finally {
			if ($maintenanceWasSet) {
				$this->config->setSystemValue('maintenance', false);
			}
		}
	}

	/**
	 * Validates against the allowlist and persists a source binding; see "Source management API".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function bindSource(string $appId, SourceBinding $binding): void {
		$this->trustedSources->assertBindingAllowed($binding);
		$this->bindingStore->set($appId, $binding);
	}

	/**
	 * Returns the persisted source binding for an app; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getBinding(string $appId): ?SourceBinding {
		return $this->bindingStore->get($appId);
	}

	public function getTrustedSources(): TrustedSourceList {
		return $this->trustedSources;
	}

	/**
	 * Curated add of a forge-qualified trusted-source pattern. Constructs
	 * `{forge}:{owner}/{repo}` (or `{forge}:{owner}/*` when repo is null),
	 * validates it (no over-broad globs), appends it to the existing patterns,
	 * and persists. Idempotent: an already-present pattern is a no-op.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return list<string> the updated pattern set
	 * @throws InvalidArgumentException on an unknown forge, empty/`*` owner, bad charset, or an over-broad result
	 */
	public function addTrustedPattern(string $forge, string $owner, ?string $repo): array {
		$pattern = $this->buildAndValidatePattern($forge, $owner, $repo);

		$patterns = $this->trustedSources->getPatterns();
		if (!in_array($pattern, $patterns, true)) {
			$patterns[] = $pattern;
			$this->trustedSources->setPatterns($patterns);
		}

		return $this->trustedSources->getPatterns();
	}

	/**
	 * Removes an exact trusted-source pattern and persists.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return list<string> the updated pattern set
	 */
	public function removeTrustedPattern(string $pattern): array {
		$patterns = array_values(array_filter(
			$this->trustedSources->getPatterns(),
			static fn (string $p): bool => $p !== $pattern,
		));
		$this->trustedSources->setPatterns($patterns);

		return $this->trustedSources->getPatterns();
	}

	/**
	 * Validates a curated allowlist entry and returns the forge-qualified glob.
	 * Guarantees a concrete owner — never a whole-forge or match-everything glob.
	 *
	 * @throws InvalidArgumentException
	 */
	private function buildAndValidatePattern(string $forge, string $owner, ?string $repo): string {
		if (!in_array($forge, [SourceBinding::FORGE_GITHUB, SourceBinding::FORGE_CODEBERG], true)) {
			throw new InvalidArgumentException('Unknown forge: ' . $forge);
		}
		$owner = trim($owner);
		if ($owner === '' || $owner === '*') {
			throw new InvalidArgumentException('A concrete owner is required (wildcard-only owners are not allowed).');
		}
		if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $owner)) {
			throw new InvalidArgumentException('Owner contains invalid characters.');
		}

		$repoPart = '*';
		if ($repo !== null && trim($repo) !== '') {
			$repo = trim($repo);
			if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $repo)) {
				throw new InvalidArgumentException('Repository contains invalid characters.');
			}
			$repoPart = $repo;
		}

		$pattern = $forge . ':' . $owner . '/' . $repoPart;
		// Defence in depth: reject anything that would trust an entire forge.
		if (in_array($pattern, ['*', '*/*', $forge . ':*', $forge . ':*/*'], true)) {
			throw new InvalidArgumentException('That pattern is too broad — it would trust an entire forge.');
		}

		return $pattern;
	}

	public function getSourceRegistry(): SourceRegistry {
		return $this->sourceRegistry;
	}

	/**
	 * Returns the breadcrumb log of whichever installer handled the request.
	 * The two installers type their logs differently, so we use the looser
	 * common type and validate shape in {@see lastStageOf()}.
	 *
	 * @return array<int, mixed>
	 */
	private function installerDebugLog(SourceInterface $source): array {
		return $source->getInstallerKind() === SourceInterface::INSTALLER_SIGNED
			? $this->signedInstaller->getDebugLog()
			: $this->externalInstaller->getDebugLog();
	}

	/**
	 * The last stage reached in a breadcrumb log, used to classify failures.
	 *
	 * @param array<int, mixed> $debugLog
	 */
	private function lastStageOf(array $debugLog): ?string {
		$last = end($debugLog);
		if (!is_array($last)) {
			return null;
		}
		/** @var mixed $stage */
		$stage = $last['stage'] ?? null;

		return is_string($stage) ? $stage : null;
	}

	private function resolveBinding(string $appId, ?string $sourceOverride): SourceBinding {
		if ($sourceOverride !== null && trim($sourceOverride) !== '') {
			$binding = SourceRegistry::parseSourceId($sourceOverride);
			if ($binding->kind === SourceBinding::KIND_GITHUB_RELEASE) {
				$this->trustedSources->assertBindingAllowed($binding);
			}

			// A one-off override that names the *same* source as the stored
			// binding must still honour that binding's recorded SHA-256 digests —
			// otherwise trust-on-first-use is silently bypassed by passing the
			// current source id explicitly. Only a genuine rebind to a different
			// source discards the digests (handled by SourceBindingStore).
			$stored = $this->bindingStore->get($appId);
			if ($stored !== null && $stored->getId() === $binding->getId()) {
				$binding = $binding->withRecordedShaMap($stored->getRecordedShaMap());
			}

			return $binding;
		}

		$stored = $this->bindingStore->get($appId);
		if ($stored !== null) {
			if ($stored->kind === SourceBinding::KIND_GITHUB_RELEASE) {
				$this->trustedSources->assertBindingAllowed($stored);
			}

			return $stored;
		}

		return SourceBinding::appStore();
	}

	private function classifyUpdateType(string $previousVersion, string $newVersion, bool $dryRun): string {
		if ($dryRun) {
			return 'dry-run';
		}
		if ($previousVersion === '') {
			return 'install';
		}
		if ($newVersion === '' || $newVersion === $previousVersion) {
			return 'none';
		}

		return version_compare($newVersion, $previousVersion, '>') ? 'upgrade' : 'downgrade';
	}

	private function classifyMessage(string $previousVersion, string $newVersion, bool $dryRun): string {
		if ($dryRun) {
			return 'Dry run mode: no changes were applied.';
		}
		if ($previousVersion === '') {
			return 'App installed.';
		}
		if ($newVersion === '' || $newVersion === $previousVersion) {
			return 'App already at selected version.';
		}

		return version_compare($newVersion, $previousVersion, '<') ? 'App downgraded.' : 'App updated.';
	}

	/**
	 * @return array{installedVersion: ?string, availableVersions: list<array{version:string, changelog:?string, recordedSha:?string}>, versions: list<array{version:string, changelog:?string, recordedSha:?string}>, source: string, sourceId: string, statusCode: int, hasError: bool, error: string}
	 */
	private function errorEnvelope(string $message, int $statusCode): array {
		return [
			'installedVersion' => null,
			'availableVersions' => [],
			'versions' => [],
			'source' => 'none',
			'sourceId' => 'none',
			'statusCode' => $statusCode,
			'hasError' => true,
			'error' => $message,
		];
	}

	/**
	 * Whether `$appId` may be installed/updated/listed through Versioniq —
	 * `false` for Versioniq itself (self-management) and any core /
	 * always-enabled app. Single shared predicate for the guard duplicated
	 * across {@see getAppVersions()}, {@see installAppVersion()}, and the
	 * `occ` commands; see "CLI trust context".
	 *
	 * @spec openspec/specs/cli-commands/spec.md
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function isManageableApp(string $appId): bool {
		return !$this->isSelfManagedApp($appId) && !$this->isCoreProtectedApp($appId);
	}

	private function isSelfManagedApp(string $appId): bool {
		return trim($appId) === Application::APP_ID;
	}

	private function isCoreProtectedApp(string $appId): bool {
		return in_array(trim($appId), $this->appManager->getAlwaysEnabledApps(), true);
	}

	private function currentActorUid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'system';
	}

	private function nowIso(): string {
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
	}
}
