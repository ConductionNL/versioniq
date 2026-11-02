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
use OCA\Versioniq\Service\Installer\InstallOptions;
use OCA\Versioniq\Service\Installer\InstallRequest;
use OCA\Versioniq\Service\Installer\InstallRun;
use OCA\Versioniq\Service\Installer\InstallTarget;
use OCA\Versioniq\Service\Installer\PinChoice;
use OCA\Versioniq\Service\Installer\ReleaseInstall;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\SourceRegistry;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCP\App\Events\AppUpdateEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
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
 * @psalm-type AppLists = array{enabled: array<string>, versions: array<string, string>, core: array<string>, info: array<string, array<string, mixed>>}
 * @psalm-type InstalledAppCard = array{id:string,label:string,description:string,summary:string,preview:string,isCore:bool,isShipped:bool,boundSourceId:?string,manageable:bool,warning:?string,installedVersion:?string,state:'enabled'|'disabled'|'notInstalled',lkg:?array{version:string,recordedAt:string,sourceId:?string}}
 */
class InstallerService {
	/** Server-side changelog size cap; see "Version listings carry release notes". */
	private const CHANGELOG_MAX_BYTES = 8192;
	private const CHANGELOG_TRUNCATION_MARKER = ' …[truncated]';

	public const OVERRIDE_PIN_REPIN = 'repin';
	public const OVERRIDE_PIN_UNPIN = 'unpin';

	/**
	 * App config switch (bool, on by default): raise the platform's
	 * AppUpdateEvent after an update through Versioniq, so the app's users are
	 * told as they are for the platform's own updates; see "Users hear what
	 * changed after an update through Versioniq".
	 */
	public const KEY_NOTIFY_USERS_ON_UPDATE = 'notify_users_on_update';

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
		private IEventDispatcher $eventDispatcher,
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
	 * @return list<InstalledAppCard>
	 */
	public function getInstalledApps(): array {
		$enabled = $this->appManager->getEnabledApps();
		$versions = $this->appManager->getAppInstalledVersions(false);
		$appIds = $this->listedAppIds($enabled, $versions);
		$lists = [
			'enabled' => $enabled,
			'versions' => $versions,
			'core' => $this->appManager->getAlwaysEnabledApps(),
			'info' => self::appInfoById(),
		];

		return array_map(
			fn (string $appId): array => $this->appCard($appId, $lists),
			$appIds
		);
	}

	/**
	 * Enabled apps, installed-but-disabled apps (Nextcloud keeps their
	 * installed version) and apps bound to a source but not installed yet,
	 * so every one of them can be picked and installed from the page; see
	 * "List Installed Apps". Versioniq itself is left out.
	 *
	 * @param array<string> $enabled
	 * @param array<string, string> $versions
	 * @return list<string>
	 */
	private function listedAppIds(array $enabled, array $versions): array {
		$appIds = array_values(array_unique(array_filter(
			array_merge($enabled, array_keys($versions), $this->bindingStore->listBoundAppIds()),
			fn (string $appId): bool => !$this->isSelfManagedApp($appId)
		)));
		sort($appIds);

		return $appIds;
	}

	/**
	 * The server's app info, keyed by app id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function appInfoById(): array {
		$appInfo = [];
		foreach ((new \OC_App())->listAllApps() as $app) {
			if (!isset($app['id']) || !is_string($app['id'])) {
				continue;
			}
			$appInfo[$app['id']] = $app;
		}

		return $appInfo;
	}

	/**
	 * One app's card for the Apps tab.
	 *
	 * @param AppLists $lists
	 * @return InstalledAppCard
	 */
	private function appCard(string $appId, array $lists): array {
		return [
			'id' => $appId,
			...self::appTexts($lists['info'][$appId] ?? [], $appId),
			...$this->appStatus($appId, $lists),
		];
	}

	/**
	 * The app's name, description, summary and preview from its app info,
	 * trimmed; the name falls back to the app id.
	 *
	 * @param array<string, mixed> $app
	 * @return array{label: string, description: string, summary: string, preview: string}
	 */
	private static function appTexts(array $app, string $appId): array {
		$name = self::trimmedText($app, 'name');

		return [
			'label' => $name !== '' ? $name : $appId,
			'description' => self::trimmedText($app, 'description'),
			'summary' => self::trimmedText($app, 'summary'),
			'preview' => self::trimmedText($app, 'preview'),
		];
	}

	/**
	 * @param array<string, mixed> $app
	 */
	private static function trimmedText(array $app, string $key): string {
		return isset($app[$key]) && is_string($app[$key]) ? trim($app[$key]) : '';
	}

	/**
	 * The app's binding, environment, state, installed version and
	 * last-known-good record for its card.
	 *
	 * @param AppLists $lists
	 * @return array{isCore: bool, isShipped: bool, boundSourceId: ?string, manageable: bool, warning: ?string, installedVersion: ?string, state: 'enabled'|'disabled'|'notInstalled', lkg: ?array{version: string, recordedAt: string, sourceId: ?string}}
	 */
	private function appStatus(string $appId, array $lists): array {
		$binding = $this->bindingStore->get($appId);
		$env = $this->appEnvironment($appId);
		$installedVersion = $this->installedVersion($appId, $lists);

		return [
			'isCore' => in_array($appId, $lists['core'], true),
			'isShipped' => $this->appManager->isShipped($appId),
			'boundSourceId' => $binding?->getId(),
			'manageable' => $env['manageable'],
			'warning' => $env['warning'],
			'installedVersion' => $installedVersion === '' ? null : $installedVersion,
			'state' => self::appState($appId, $lists),
			'lkg' => $this->lkgStore->get($appId)?->toArray(),
		];
	}

	/**
	 * Whether the app's folder can be managed here (a bind-mounted dev
	 * checkout that is not writable cannot). When the path is unknown the
	 * defaults stand; the install-time guard still applies.
	 *
	 * @return array{manageable: bool, warning: ?string}
	 */
	private function appEnvironment(string $appId): array {
		try {
			return $this->environmentCheck->inspect($this->appManager->getAppPath($appId));
		} catch (\Throwable) {
			return ['manageable' => true, 'warning' => null];
		}
	}

	/**
	 * @param AppLists $lists
	 * @return 'enabled'|'disabled'|'notInstalled'
	 */
	private static function appState(string $appId, array $lists): string {
		if (in_array($appId, $lists['enabled'], true)) {
			return 'enabled';
		}

		return isset($lists['versions'][$appId]) ? 'disabled' : 'notInstalled';
	}

	/**
	 * The installed version of an installed app, from the app manager or else
	 * from Nextcloud's record of installed versions; '' when there is none.
	 *
	 * @param AppLists $lists
	 */
	private function installedVersion(string $appId, array $lists): string {
		if (self::appState($appId, $lists) === 'notInstalled') {
			return '';
		}
		try {
			$version = $this->appManager->getAppVersion($appId, false);
		} catch (Exception) {
			$version = '';
		}

		return $version !== '' ? $version : ($lists['versions'][$appId] ?? '');
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
		$versions = $this->applyCachedServerCompatibility($versions, $appId);

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
	 * @param list<array{version:string, changelog?:?string, serverCompatible?:?bool, releasedAt?:?string}> $versions
	 * @return list<array{version:string, changelog:?string, serverCompatible?:?bool, releasedAt?:?string}>
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
	 * @param list<array{version:string, changelog:?string, serverCompatible?:?bool, releasedAt?:?string}> $versions
	 * @return list<array{version:string, changelog:?string, serverCompatible?:?bool, releasedAt?:?string, recordedSha:?string}>
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
	 * Stamps `serverCompatible` on cached versions that do not carry it yet,
	 * from the range in the cached artifact's own info.xml. Forge releases
	 * publish no range, so without this every one of them read as unknown
	 * (#435). A verdict the source already gave (the App Store's
	 * platformVersionSpec) is kept; an uncached forge release stays unknown.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param list<array<string, mixed>> $versions
	 * @return list<array<string, mixed>>
	 */
	private function applyCachedServerCompatibility(array $versions, string $appId): array {
		$serverVersion = $this->serverCoreVersion();
		if ($serverVersion === '') {
			return $versions;
		}

		return array_map(
			function (array $entry) use ($appId, $serverVersion): array {
				$version = $entry['version'] ?? null;
				if (($entry['cachedOffline'] ?? false) !== true || isset($entry['serverCompatible']) || !is_string($version)) {
					return $entry;
				}
				$spec = $this->artifactCache->platformSpecFor($appId, $version);
				if ($spec !== null) {
					$entry['serverCompatible'] = AppStoreSource::satisfiesPlatformSpec($serverVersion, $spec);
				}

				return $entry;
			},
			$versions
		);
	}

	/**
	 * The running server's version as `major.minor.patch`, or '' when unknown.
	 */
	private function serverCoreVersion(): string {
		$core = array_slice(explode('.', $this->config->getSystemValueString('version', '')), 0, 3);
		foreach ($core as $part) {
			if (!ctype_digit($part)) {
				return '';
			}
		}

		return implode('.', $core);
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
		// $dryRun falls back to $includeDebug only when the caller does not
		// pass it at all; see MODIFIED "Debug Mode" — "Legacy behavior preserved".
		return $this->runInstall(new InstallRequest(
			new InstallTarget(trim($appId), trim($targetVersion), $sourceOverride),
			new InstallOptions($includeDebug, $dryRun ?? $includeDebug, $acceptNewSha, $allowDowngrade),
			new PinChoice($overridePin, $pinRequested),
		));
	}

	/**
	 * Runs one install request through its guards, in order, and then installs.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function runInstall(InstallRequest $request): array {
		$refusal = $this->requestRefusal($request);
		if ($refusal !== null) {
			return $refusal;
		}
		$run = $this->openInstallRun($request);
		if (is_array($run)) {
			return $run;
		}

		return $this->installRunRefusal($run) ?? $this->installRelease($run);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function installEnvelope(int $statusCode, array $payload): array {
		return ['statusCode' => $statusCode, 'payload' => $payload];
	}

	/**
	 * The app id, both versions, `$details` and the source id, in that order.
	 *
	 * @param array<string, mixed> $details
	 * @return array<string, mixed>
	 */
	private function outcomePayload(InstallRun $run, array $details): array {
		$base = [
			'appId' => $run->request->target->appId,
			'fromVersion' => $run->fromVersion(),
			'toVersion' => $run->request->target->version,
		];

		return $base + $details + ['sourceId' => $run->binding->getId()];
	}

	/**
	 * The app id, the target version, `$message`, the source id and an empty debug log.
	 *
	 * @return array<string, mixed>
	 */
	private function briefPayload(InstallRun $run, string $message): array {
		return [
			'appId' => $run->request->target->appId,
			'toVersion' => $run->request->target->version,
			'message' => $message,
			'sourceId' => $run->binding->getId(),
		] + $run->debugSuffix([]);
	}

	/**
	 * Refuses a request without app id or version, for an app Versioniq does
	 * not manage, or with an unknown pin override.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function requestRefusal(InstallRequest $request): ?array {
		$target = $request->target;
		if ($target->appId === '' || $target->version === '') {
			return $this->installEnvelope(Http::STATUS_BAD_REQUEST, ['message' => 'Missing app id or version.']);
		}
		if (!$this->isManageableApp($target->appId)) {
			return $this->installEnvelope(Http::STATUS_FORBIDDEN, [
				'appId' => $target->appId,
				'toVersion' => $target->version,
				'message' => $this->isCoreProtectedApp($target->appId)
					? 'This core app cannot be installed or updated from Versioniq.'
					: 'This app cannot be installed or updated from Versioniq.',
			]);
		}

		return $this->overridePinRefusal($request);
	}

	/**
	 * Refuses a pin override other than `repin` or `unpin`; the caller
	 * rejects it before this point in the normal flow.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function overridePinRefusal(InstallRequest $request): ?array {
		$overridePin = $request->pinChoice->overridePin;
		if ($overridePin === null || $overridePin === self::OVERRIDE_PIN_REPIN || $overridePin === self::OVERRIDE_PIN_UNPIN) {
			return null;
		}

		return $this->installEnvelope(Http::STATUS_BAD_REQUEST, [
			'appId' => $request->target->appId,
			'toVersion' => $request->target->version,
			'message' => 'overridePin must be "repin" or "unpin".',
		]);
	}

	/**
	 * Resolves the source binding against the allowlist and reads the
	 * installed version; a bad or untrusted source is refused.
	 *
	 * @return InstallRun|array{statusCode:int, payload:array<string, mixed>}
	 */
	private function openInstallRun(InstallRequest $request): InstallRun|array {
		try {
			$binding = $this->resolveBinding($request->target->appId, $request->target->sourceOverride);
			$this->trustedSources->assertBindingAllowed($binding);
		} catch (InvalidArgumentException $error) {
			return $this->installEnvelope(Http::STATUS_BAD_REQUEST, ['message' => $error->getMessage()]);
		} catch (UntrustedSourceException $error) {
			return $this->installEnvelope(Http::STATUS_FORBIDDEN, ['message' => $error->getMessage()]);
		}

		return new InstallRun($request, $binding, $this->freshInstalledVersion($request->target->appId));
	}

	/** The installed version read past the apps cache, or '' when there is none. */
	private function freshInstalledVersion(string $appId): string {
		try {
			$this->appManager->clearAppsCache();

			return $this->appManager->getAppVersion($appId, false);
		} catch (Exception) {
			return '';
		}
	}

	/**
	 * The guards that run before any download: downgrade, pin, same version
	 * and destination pre-flight. The pin is read after the downgrade guard.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function installRunRefusal(InstallRun $run): ?array {
		$refusal = $this->downgradeRefusal($run);
		if ($refusal !== null) {
			return $refusal;
		}
		$run->pin = $this->pinStore->get($run->request->target->appId);

		return $this->pinRefusal($run) ?? $this->sameVersionOutcome($run) ?? $this->preflightRefusal($run);
	}

	/**
	 * Server-side downgrade guard: refuses a real (non-dry-run) downgrade
	 * before any download unless explicitly acknowledged — see "Server-side
	 * downgrade guard". Dry-run requests evaluate this same condition but are
	 * never blocked by it (they report via updateType/orphanedMigrations
	 * instead) — see "Dry-run requests MUST evaluate and report the guard
	 * without requiring the flag". Nothing was attempted, so no debug log.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function downgradeRefusal(InstallRun $run): ?array {
		$options = $run->request->options;
		if (!$this->isDowngradeRun($run) || $options->dryRun || $options->allowDowngrade) {
			return null;
		}
		$category = FailureClassifier::CATEGORY_DOWNGRADE_GUARD;

		return $this->installEnvelope($this->failureClassifier->httpStatusFor($category), $this->outcomePayload($run, [
			'message' => $this->failureClassifier->messageFor($category),
			'category' => $category,
			'hint' => $this->failureClassifier->downgradeGuardHint($run->installedVersion, $run->request->target->version),
			'installStatus' => 'failed',
		]));
	}

	private function isDowngradeRun(InstallRun $run): bool {
		return $run->installedVersion !== '' && version_compare($run->request->target->version, $run->installedVersion, '<');
	}

	/**
	 * Pin guard: Versioniq's own install path refuses to overwrite a pinned
	 * app without an explicit override — see "Pins are enforced on
	 * Versioniq's own install path". Reinstalling the pinned version itself
	 * is never blocked (no drift, nothing to override).
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function pinRefusal(InstallRun $run): ?array {
		$pin = $run->pinBeingOverridden();
		if ($pin === null || $run->request->pinChoice->overridePin !== null) {
			return null;
		}

		return $this->installEnvelope(Http::STATUS_CONFLICT, $this->outcomePayload($run, [
			'message' => sprintf('This app is pinned to version %s. Pass overridePin=repin or overridePin=unpin to proceed.', $pin->version),
			'category' => 'pinned',
			'pinnedVersion' => $pin->version,
		]) + $run->debugSuffix([]));
	}

	/**
	 * Answers a request for the version that is already installed, clearing a
	 * stale drift marker of a pin on that version.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function sameVersionOutcome(InstallRun $run): ?array {
		if ($run->installedVersion === '' || !version_compare($run->request->target->version, $run->installedVersion, '=')) {
			return null;
		}
		$this->clearPinDrift($run);

		return $this->installEnvelope(Http::STATUS_OK, $this->outcomePayload($run, [
			'message' => 'App already has this version installed.',
		]) + $run->debugSuffix([]));
	}

	/**
	 * Re-pin after drift (the app was restored to, or reinstalled at, the
	 * pinned version) clears the drift markers — see "Re-pin reinstalls the
	 * pinned version".
	 */
	private function clearPinDrift(InstallRun $run): void {
		$pin = $run->pin;
		if ($pin !== null && $run->request->target->version === $pin->version && $pin->hasDrifted()) {
			$this->pinStore->set($run->request->target->appId, new Pin($pin->version, $pin->pinnedBy, $pin->pinnedAt, $pin->reason));
		}
	}

	/**
	 * Pre-flight: replacing an existing app renames its folder, which needs a
	 * writable parent directory. A bind-mounted dev checkout (owned by another
	 * uid) is not writable by the web-server user, so the install can never
	 * succeed — fail fast before downloading anything.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}|null
	 */
	private function preflightRefusal(InstallRun $run): ?array {
		$existingPath = $this->existingAppPath($run->request->target->appId);
		if ($existingPath === null || $this->environmentCheck->isDestinationWritable($existingPath)) {
			return null;
		}
		$category = FailureClassifier::CATEGORY_PREFLIGHT_PERMISSION;

		return $this->installEnvelope($this->failureClassifier->httpStatusFor($category), $this->outcomePayload($run, [
			'message' => $this->failureClassifier->messageFor($category),
			'category' => $category,
			'stage' => FailureClassifier::STAGE_REQUESTED,
			'hint' => $this->failureClassifier->hintFor($category),
			'installStatus' => 'failed',
		]) + $run->debugSuffix([]));
	}

	private function existingAppPath(string $appId): ?string {
		try {
			return $this->appManager->getAppPath($appId);
		} catch (Exception) {
			return null;
		}
	}

	/**
	 * Resolves the release in the bound source and installs it.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function installRelease(InstallRun $run): array {
		$source = $this->sourceRegistry->get($run->binding);
		$release = $source->resolveRelease($run->request->target->appId, $run->request->target->version, $run->binding);
		if ($release === null) {
			return $this->installEnvelope(Http::STATUS_NOT_FOUND, $this->briefPayload($run, 'Requested version not found in source metadata.'));
		}
		if (isset($release['error']) && is_string($release['error'])) {
			return $this->installEnvelope(Http::STATUS_BAD_REQUEST, $this->briefPayload($run, $release['error']));
		}

		return $this->installFromSource(new ReleaseInstall($run, $source, $release));
	}

	/**
	 * Installs under maintenance mode (never for a dry run, which changes
	 * nothing and so never locks users out, #427) and reports each failure
	 * with its classification.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function installFromSource(ReleaseInstall $install): array {
		$maintenanceWasSet = false;
		try {
			if ($this->shouldEnterMaintenance($install->run)) {
				$maintenanceWasSet = true;
				$this->config->setSystemValue('maintenance', true);
			}

			return $this->installAndReport($install);
		} catch (UntrustedSourceException $error) {
			return $this->installEnvelope(Http::STATUS_FORBIDDEN, $this->briefPayload($install->run, $error->getMessage()));
		} catch (ShaMismatchException $error) {
			return $this->shaMismatchOutcome($install, $error);
		} catch (InstallFailure $failure) {
			return $this->installFailureOutcome($install, $failure);
		} catch (Exception $error) {
			return $this->classifiedFailureOutcome($install, $error);
		} finally {
			if ($maintenanceWasSet) {
				$this->config->setSystemValue('maintenance', false);
			}
		}
	}

	private function shouldEnterMaintenance(InstallRun $run): bool {
		return !$run->request->options->dryRun && !$this->config->getSystemValueBool('maintenance', false);
	}

	/**
	 * Runs the installer, persists the binding, reads the installed version
	 * back and, after a real install, settles the pin and raises the update
	 * event.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function installAndReport(ReleaseInstall $install): array {
		$run = $install->run;
		$appId = $run->request->target->appId;
		$result = $this->runInstaller($install);
		if (!$run->request->options->dryRun) {
			$this->appManager->clearAppsCache();
			$this->bindingStore->set($appId, $run->binding);
		}
		$this->appManager->clearAppsCache();
		$install->appVersion = $this->versionAfterInstall($appId);
		$payload = $this->successPayload($install, $result);
		if (!$run->request->options->dryRun) {
			$this->settlePin($install);
			// After the pin is settled, so our own listener does not read a
			// re-pinned install as drift.
			$this->dispatchUpdateEvent($appId, $run->installedVersion, $install->appVersion);
		}

		return $this->installEnvelope(Http::STATUS_OK, $payload);
	}

	/**
	 * Installs through the signed installer for an App Store source, which
	 * reports no integrity warning and no recorded-digest match, or through
	 * the external installer otherwise.
	 *
	 * @return array<string, mixed>
	 */
	private function runInstaller(ReleaseInstall $install): array {
		if ($install->source->getInstallerKind() === SourceInterface::INSTALLER_SIGNED) {
			$result = $this->signedInstaller->installFromSelectedRelease($install->run->request->target->appId, $install->release, $install->run->request->options->dryRun);
			unset($result['integrityWarning'], $result['recordedShaMatched']);

			return $result;
		}

		return $this->runExternalInstaller($install);
	}

	/**
	 * The external installer may have recorded or replaced a SHA-256 on the
	 * binding; the run keeps that updated binding, not the pre-install one, so
	 * the digest is not lost — see "SHA-256 recorded on first successful
	 * external install".
	 *
	 * @return array<string, mixed>
	 */
	private function runExternalInstaller(ReleaseInstall $install): array {
		$run = $install->run;
		$target = $run->request->target;
		$result = $this->externalInstaller->installFromExternalRelease($target->appId, $target->version, $install->release, $run->binding, $run->request->options->dryRun, $run->request->options->acceptNewSha);
		$run->binding = $result['binding'];

		return $result;
	}

	/** The version the app reports after the install: info.xml, then the app manager, then `installed_version`. */
	private function versionAfterInstall(string $appId): string {
		$appVersion = $this->appInfoVersion($appId);
		if ($appVersion === null) {
			$appVersion = $this->appManager->getAppVersion($appId, false);
		}
		$configuredVersion = $this->appConfig->getValueString($appId, 'installed_version', $appVersion);

		return $configuredVersion !== '' ? $configuredVersion : $appVersion;
	}

	private function appInfoVersion(string $appId): ?string {
		try {
			$appPath = $this->appManager->getAppPath($appId, true);
			$appInfo = $this->appManager->getAppInfoByPath($appPath . '/appinfo/info.xml');
			if (is_array($appInfo) && isset($appInfo['version']) && is_string($appInfo['version'])) {
				return $appInfo['version'];
			}
		} catch (Exception) {
			return null;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private function successPayload(ReleaseInstall $install, array $result): array {
		$run = $install->run;
		$dryRun = $run->request->options->dryRun;
		$payload = [
			'appId' => $run->request->target->appId,
			'fromVersion' => $run->fromVersion(),
			'toVersion' => $dryRun ? $run->request->target->version : $install->appVersion,
			'installedVersion' => $install->appVersion,
			'updateType' => $this->classifyUpdateType($run->installedVersion, $install->appVersion, $dryRun),
			'message' => $this->classifyMessage($run->installedVersion, $install->appVersion, $dryRun),
			'dryRun' => $dryRun,
			'installStatus' => $result['status'] ?? 'unknown',
			'sourceId' => $run->binding->getId(),
			// See "Cached fallback with full re-verification": the outcome
			// states when the download fell back to a locally cached artifact.
			'servedFromCache' => (bool)($result['servedFromCache'] ?? false),
		];

		return $payload + $this->optionalResultFields($result) + $run->debugSuffix((array)($result['debug'] ?? []));
	}

	/**
	 * The integrity warning and the recorded-digest match when the installer
	 * reported them (see "Recorded SHA-256 enforced on reinstall"), and the
	 * migration diff, present only for a downgrade (see "Migration diff on
	 * downgrade"): `null` means it could not be computed, `[]` that no schema
	 * steps differ.
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private function optionalResultFields(array $result): array {
		$fields = array_filter([
			'integrityWarning' => $result['integrityWarning'] ?? null,
			'recordedShaMatched' => $result['recordedShaMatched'] ?? null,
		], static fn (mixed $value): bool => $value !== null);
		if (array_key_exists('orphanedMigrations', $result)) {
			// The signed installer's result is loosely typed (array<string, mixed>),
			// so the value is annotated rather than inferred.
			/** @var list<string>|null $orphanedMigrations */
			$orphanedMigrations = $result['orphanedMigrations'];
			$fields['orphanedMigrations'] = $orphanedMigrations;
		}

		return $fields;
	}

	/**
	 * Pin state changes only after a real (non-dry-run) install success; see
	 * "Pins are enforced on Versioniq's own install path". Adjusting the pin
	 * here, inside the same request and before returning, is what keeps a
	 * later drift check from misreading our own override as drift.
	 */
	private function settlePin(ReleaseInstall $install): void {
		$run = $install->run;
		$overridden = $run->pinBeingOverridden();
		if ($overridden !== null) {
			$this->applyPinOverride($install, $overridden);
			return;
		}
		if ($run->pin === null && $run->request->pinChoice->pinRequested) {
			$this->pinStore->set($run->request->target->appId, new Pin($install->appVersion, $this->currentActorUid(), $this->nowIso()));
			return;
		}
		$this->clearPinDrift($run);
	}

	/**
	 * An overridden pin moves to the installed version (`repin`) or is
	 * cleared (`unpin`); the pin guard already refused a missing override.
	 */
	private function applyPinOverride(ReleaseInstall $install, Pin $pin): void {
		$appId = $install->run->request->target->appId;
		if ($install->run->request->pinChoice->overridePin === self::OVERRIDE_PIN_REPIN) {
			$this->pinStore->set($appId, new Pin($install->appVersion, $this->currentActorUid(), $this->nowIso(), $pin->reason));
			return;
		}
		$this->pinStore->clear($appId, $this->currentActorUid());
	}

	/**
	 * Recorded-digest mismatch: no filesystem change happened (thrown before
	 * extraction/backup). The machine-readable `code` lets the frontend
	 * render the explicit "accept new checksum" escape hatch; see "Recorded
	 * SHA-256 enforced on reinstall".
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function shaMismatchOutcome(ReleaseInstall $install, ShaMismatchException $error): array {
		$run = $install->run;
		$classification = $this->failureClassifier->classify($error, FailureClassifier::STAGE_CHECKSUM, FailureClassifier::CATEGORY_SHA_MISMATCH);
		$payload = $this->outcomePayload($run, [
			'message' => $error->getMessage(),
			'category' => $classification['category'],
			'code' => 'sha_mismatch',
			'stage' => FailureClassifier::STAGE_CHECKSUM,
			'hint' => $classification['hint'],
			'installStatus' => 'failed',
		]) + ['expectedSha' => $error->expectedSha, 'actualSha' => $error->actualSha];
		if ($run->request->options->includeDebug) {
			$payload['debug'] = $this->installerDebugLog($install->source);
		}

		return $this->installEnvelope($classification['statusCode'], $payload);
	}

	/**
	 * The installer already handled filesystem recovery; this reports the
	 * honest outcome (reverted / installed-but-broken) instead of a 500.
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function installFailureOutcome(ReleaseInstall $install, InstallFailure $failure): array {
		$debugLog = $this->installerDebugLog($install->source);
		$isBroken = $failure->getOutcome() === InstallFailure::OUTCOME_INSTALLED_BUT_BROKEN;
		$category = $isBroken
			? FailureClassifier::CATEGORY_FINALIZE
			: $this->failureClassifier->categoryFor($failure, $failure->getStage());
		$hint = $isBroken
			? $this->failureClassifier->finalizeHint($failure->getRestoreState())
			: $this->failureClassifier->revertedHint();

		return $this->installEnvelope($this->failureClassifier->httpStatusFor($category), $this->outcomePayload($install->run, [
			'message' => $failure->getMessage(),
			'category' => $category,
			'stage' => $failure->getStage(),
			'hint' => $hint,
			'installStatus' => $failure->getOutcome(),
		]) + $install->run->debugSuffix($debugLog));
	}

	/**
	 * Classified failure: stage, category and hint are attached regardless of
	 * debug, and the HTTP status follows the category (no blanket 500).
	 *
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	private function classifiedFailureOutcome(ReleaseInstall $install, Exception $error): array {
		$debugLog = $this->installerDebugLog($install->source);
		$lastStage = $this->lastStageOf($debugLog);
		$classification = $this->failureClassifier->classify($error, $lastStage);

		return $this->installEnvelope($classification['statusCode'], $this->outcomePayload($install->run, [
			'message' => $error->getMessage(),
			'category' => $classification['category'],
			'stage' => $lastStage,
			'hint' => $classification['hint'],
			'installStatus' => 'failed',
		]) + $install->run->debugSuffix($debugLog));
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

	/**
	 * Removes an app's source binding, so the app is read from the App Store
	 * again and any digests recorded on the old binding are dropped; see
	 * "Source binding" (#438).
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function clearBinding(string $appId): void {
		$this->bindingStore->clear($appId);
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
		if (!in_array($forge, SourceBinding::FORGES, true)) {
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

	/**
	 * Raises the platform's AppUpdateEvent for a real version change of an
	 * installed app, as Nextcloud's own update path does, unless the admin
	 * switched it off. A fresh install or a reinstall of the same version is
	 * not an update and raises nothing.
	 *
	 * @spec openspec/changes/releases-notes-reach/tasks.md#task-1.1
	 */
	private function dispatchUpdateEvent(string $appId, string $fromVersion, string $toVersion): void {
		if ($fromVersion === '' || $toVersion === '' || $fromVersion === $toVersion) {
			return;
		}
		if (!$this->appConfig->getValueBool(Application::APP_ID, self::KEY_NOTIFY_USERS_ON_UPDATE, true)) {
			return;
		}

		$this->eventDispatcher->dispatchTyped(new AppUpdateEvent($appId));
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
