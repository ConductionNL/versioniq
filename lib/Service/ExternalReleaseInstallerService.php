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
use OC\Archive\TAR;
use OC\Archive\ZIP;
use OC\Files\FilenameValidator;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatResolver;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\ServerVersion;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Installs an app from an external release source (e.g. GitHub).
 *
 * Differs from `SelectedReleaseInstallerService` in that there is no
 * Nextcloud-issued code-signing certificate to verify and no per-release
 * signature to check. To compensate we apply:
 *   1. Trusted-source allowlist gate (no download until passed)
 *   2. Trust-on-first-use: the downloaded archive's SHA-256 MUST match the
 *      digest recorded on the binding from a previous successful install of
 *      the same (appId, version, source), if one is recorded — before
 *      extraction/backup, `acceptNewSha` bypasses once and replaces on
 *      success; see "Recorded SHA-256 enforced on reinstall"
 *   3. Optional SHA-256 verification when the release publishes a sibling .sha256 asset
 *   4. Mandatory appId match against the extracted `appinfo/info.xml`
 *   5. Mandatory version match against the extracted `appinfo/info.xml`
 *
 * The post-extract finalization (migrations, repair steps, config writes) is
 * delegated to `InstallFinalizer` so signed and external installs cannot drift
 * on upgrade semantics.
 *
 * @psalm-api
 */
class ExternalReleaseInstallerService {
	/** @var list<array{stage: string, data: mixed}> */
	private array $debug = [];

	/** Temp path of the archive verified by the current install; see "Persist verified artifacts on successful install". */
	private ?string $lastArchivePath = null;

	/** @var ?array{sha256: string, sourceId: ?string, installerKind: string} */
	private ?array $lastArchiveMeta = null;

	/** Whether the current install's archive was served from {@see ArtifactCache} after a download failure; see "Cached fallback with full re-verification". */
	private bool $servedFromCache = false;

	public function __construct(
		private IClientService $clientService,
		private ITempManager $tempManager,
		private IAppManager $appManager,
		private IConfig $config,
		private IAppConfig $appConfig,
		private InstallFinalizer $finalizer,
		private TrustedSourceList $trustedSources,
		private LoggerInterface $logger,
		private PatResolver $patResolver,
		private PatManager $patManager,
		private IUserSession $userSession,
		private AuditLogger $auditLogger,
		private MigrationDiffer $migrationDiffer,
		private ArtifactCache $artifactCache,
		private IFactory $l10nFactory,
		private ServerVersion $serverVersion,
		/**
		 * The app container, used only for OC\Files\FilenameValidator. That
		 * class is private core API with no OCP interface, so it is resolved at
		 * call time rather than type-hinted here.
		 */
		private ContainerInterface $container,
	) {
	}

	/**
	 * @return list<array{stage: string, data: mixed}>
	 */
	public function getDebugLog(): array {
		return $this->debug;
	}

	/**
	 * Downloads, integrity-checks (allowlist, recorded SHA-256, sibling SHA-256,
	 * appId/version), and installs an external release; see "External install
	 * integrity checks", "SHA-256 recorded on first successful external
	 * install", and "Recorded SHA-256 enforced on reinstall".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @param array<string, mixed> $release
	 * @param bool $acceptNewSha Single-request bypass of the recorded-SHA-256
	 *                           check; on success the recorded digest is replaced (password-confirmed
	 *                           at the API layer, warning-logged and audited here).
	 * @return array{status: string, installedVersionBefore: ?string, installedApp?: string, integrityWarning?: ?string, dryRun: bool, debug: list<array{stage: string, data: mixed}>, binding: SourceBinding, recordedShaMatched: bool, servedFromCache: bool, orphanedMigrations?: list<string>|null}
	 * @throws Exception
	 * @throws ShaMismatchException
	 */
	public function installFromExternalRelease(
		string $appId,
		string $version,
		array $release,
		SourceBinding $binding,
		bool $dryRun = false,
		bool $acceptNewSha = false,
	): array {
		$this->resetDebug();
		$this->addDebug('requested-install', [
			'appId' => $appId,
			'version' => $version,
			'sourceId' => $binding->getId(),
			'dryRun' => $dryRun,
		]);

		try {
			$installedVersion = $this->appManager->getAppVersion($appId);
		} catch (Exception) {
			$installedVersion = '';
		}

		try {
			if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $appId)) {
				throw new Exception('Invalid app id.');
			}

			$this->trustedSources->assertBindingAllowed($binding);

			$downloadUrl = isset($release['download']) && is_string($release['download']) ? $release['download'] : '';
			$shaUrl = isset($release['sha256Url']) && is_string($release['sha256Url']) && $release['sha256Url'] !== ''
				? $release['sha256Url']
				: null;
			if ($downloadUrl === '') {
				throw new Exception('No download URL found for the selected release.');
			}

			$previousEnabled = $this->appConfig->getValueString($appId, 'enabled', 'no');

			$tempFile = $this->tempManager->getTemporaryFile('.tar.gz');
			$tempFolder = $this->tempManager->getTemporaryFolder('app-version-external');
			if (!is_string($tempFile) || !is_string($tempFolder)) {
				throw new Exception('Could not allocate temporary download paths.');
			}

			$authResolution = $this->resolveAuth($binding);
			$this->addDebug('auth-resolution', ['hasPat' => $authResolution !== null]);

			try {
				$this->authenticatedDownload($downloadUrl, $tempFile, $authResolution);
			} catch (Exception $error) {
				// Download failed (network error, dead URL, deleted release
				// asset): fall back to a cached artifact for this exact
				// appId+version, if one exists — see "Cached fallback with
				// full re-verification". The allowlist gate already ran
				// above (line ~133), so a cache hit for an untrusted source
				// is unreachable here. The recorded-SHA (TOFU) and sibling
				// `.sha256` checks below run against the cache-served bytes
				// exactly as they would a fresh download.
				$cached = $this->artifactCache->fetch($appId, $version);
				if ($cached === null || file_put_contents($tempFile, $cached['content']) === false) {
					throw new Exception('Could not download selected release: ' . $error->getMessage());
				}
				$this->servedFromCache = true;
				$this->addDebug('served-from-cache', ['appId' => $appId, 'version' => $version]);
			}
			$this->addDebug('downloaded', ['tempFile' => $tempFile, 'sourceUrl' => $downloadUrl, 'servedFromCache' => $this->servedFromCache]);

			// Hash the downloaded archive exactly once; both the recorded-digest
			// (TOFU) check below and the sibling `.sha256` verification reuse
			// this value — see design.md "Enforcement point".
			$actualSha = hash_file('sha256', $tempFile);
			if ($actualSha === false) {
				throw new Exception('Could not compute SHA-256 of downloaded archive.');
			}
			$actualSha = strtolower($actualSha);

			// Trust-on-first-use enforcement: a digest recorded from a previous
			// successful install of this (appId, version, source) outranks
			// whatever the source serves now, including a co-published,
			// possibly-rewritten `.sha256` sibling — see "Recorded SHA-256
			// enforced on reinstall". Checked before extraction/backup: no
			// filesystem change happens on mismatch.
			$recordedSha = $binding->getRecordedSha($version);
			$recordedShaMatched = $recordedSha !== null && hash_equals($recordedSha, $actualSha);
			if ($recordedSha !== null && !$acceptNewSha && !$recordedShaMatched) {
				throw new ShaMismatchException($appId, $version, $recordedSha, $actualSha);
			}
			$this->addDebug('recorded-sha-check', [
				'recordedSha' => $recordedSha,
				'actualSha' => $actualSha,
				'matched' => $recordedShaMatched,
				'acceptNewSha' => $acceptNewSha,
			]);

			$integrityWarning = $this->verifyChecksum($actualSha, $shaUrl, $authResolution);
			$this->addDebug('checksum', ['shaUrl' => $shaUrl, 'integrityWarning' => $integrityWarning]);

			// Captured for the caller to persist via ArtifactCache::store()
			// once finalize() succeeds — see "Persist verified artifacts on
			// successful install".
			$this->lastArchivePath = $tempFile;
			$this->lastArchiveMeta = [
				'sha256' => $actualSha,
				'sourceId' => $binding->getId(),
				'installerKind' => SourceInterface::INSTALLER_EXTERNAL,
			];

			$archivePath = $this->extractArchive($tempFile, $tempFolder);
			$this->addDebug('archive-extracted', ['extractedRoot' => $archivePath]);

			$info = $this->parseAndValidateInfoXml($archivePath, $appId, $version);
			$this->addDebug('info-validated', [
				'appId' => $info['id'],
				'archiveVersion' => $info['version'],
			]);

			try {
				$previousPath = $this->appManager->getAppPath($appId);
			} catch (AppPathNotFoundException) {
				$previousPath = null;
			}

			$destination = $previousPath !== null ? $previousPath : $this->getInstallPath() . '/' . $appId;

			if (!is_dir(dirname($destination))) {
				throw new Exception('Could not resolve app install folder.');
			}

			// Migration diff (downgrade only, acknowledged or dry-run): compare
			// the installed copy's migration steps against the just-extracted
			// target archive before any file swap — see "Migration diff on
			// downgrade". A diff failure degrades to `null` (generic warning);
			// it never blocks the downgrade itself.
			$isDowngrade = $installedVersion !== '' && version_compare($version, $installedVersion, '<');
			$orphanedMigrations = $isDowngrade
				? $this->migrationDiffer->diff($previousPath, $archivePath)
				: null;
			if ($isDowngrade) {
				$this->addDebug('migration-diff', ['orphanedMigrations' => $orphanedMigrations]);
			}

			if ($dryRun) {
				$this->addDebug('dry-run-skip-filesystem', ['destination' => $destination]);

				return [
					'status' => 'dry-run',
					'installedVersionBefore' => $installedVersion === '' ? null : $installedVersion,
					'integrityWarning' => $integrityWarning,
					'dryRun' => true,
					'debug' => $this->debug,
					'binding' => $binding,
					'recordedShaMatched' => $recordedShaMatched,
					'servedFromCache' => $this->servedFromCache,
				] + ($isDowngrade ? ['orphanedMigrations' => $orphanedMigrations] : []);
			}

			$backupDestination = null;
			if (is_dir($destination)) {
				$backupDestination = $destination . '.appversion-backup';
				if (is_dir($backupDestination)) {
					$this->rmdirr($backupDestination);
				}
				if (!rename($destination, $backupDestination)) {
					throw new Exception('Could not backup existing app folder before replacement.');
				}
			}

			try {
				if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
					throw new Exception('Could not create app destination folder.');
				}
				$this->copyRecursive($archivePath, $destination);
			} catch (Exception $error) {
				// Pre-finalize failure: restore the previous files and report a clean
				// revert (the previously installed version is intact). For a fresh
				// install (no backup) there is nothing to restore — remove the
				// partially-copied new files so we don't leave a broken app folder.
				if ($backupDestination === null) {
					if (is_dir($destination)) {
						$this->rmdirr($destination);
					}
				} else {
					$this->restoreFromBackup($destination, $backupDestination);
				}
				throw InstallFailure::reverted($error->getMessage(), 'copy', $error);
			}

			if (function_exists('opcache_reset')) {
				opcache_reset();
			}
			$this->addDebug('filesystem-updated', ['destination' => $destination]);

			$enabled = $installedVersion === '' ? 'no' : $previousEnabled;

			// Finalize (migrations + repair steps) is the last, unrecoverable phase.
			// Keep the backup until it succeeds; on failure restore the previous
			// files and report installed-but-broken.
			try {
				$installedApp = $this->finalizer->finalize($destination, $info, $enabled, null, $binding->getId());
			} catch (\Throwable $finalizeError) {
				// Throwable, not just Exception: a finalize-phase Error (e.g. a
				// core API removed between releases) must still restore the
				// previous files and report installed-but-broken, never surface
				// as an uncaught fatal that leaves the app half-swapped.
				$restoreState = $backupDestination === null
					? FailureClassifier::RESTORE_NONE
					: ($this->restoreFromBackup($destination, $backupDestination) ? FailureClassifier::RESTORE_CLEAN : FailureClassifier::RESTORE_FAILED);
				throw InstallFailure::finalizeFailed($finalizeError->getMessage(), $restoreState, $finalizeError);
			}

			// Finalize succeeded — now it is safe to drop the backup.
			if ($backupDestination !== null && is_dir($backupDestination)) {
				$this->rmdirr($backupDestination);
			}
			$this->addDebug('finalized', ['appId' => $installedApp, 'enabled' => $enabled]);

			// Persist the just-verified archive for future rollback; see
			// "Persist verified artifacts on successful install". Only
			// reached after finalize() succeeded, so a failed install never
			// populates the cache.
			if ($this->lastArchivePath !== null && $this->lastArchiveMeta !== null) {
				$this->artifactCache->store($appId, $version, $this->lastArchivePath, $this->lastArchiveMeta);
			}

			// Record the observed digest only now that the install fully
			// succeeded (never on a failure path) — see "SHA-256 recorded on
			// first successful external install". Sibling-verified or locally
			// computed, the value is the same: verifyChecksum() above already
			// threw if a sibling digest existed and disagreed with $actualSha.
			$updatedBinding = $binding->withRecordedSha($version, $actualSha);
			$shaAccepted = $recordedSha !== null && !$recordedShaMatched;
			$auditMessage = $integrityWarning;
			if ($shaAccepted) {
				$this->logger->warning('ExternalReleaseInstallerService: accepted a new SHA-256 for a previously recorded version (acceptNewSha override)', [
					'appId' => $appId,
					'version' => $version,
					'previousSha' => $recordedSha,
					'newSha' => $actualSha,
				]);
				$shaAcceptNote = sprintf(
					'SHA-256 override accepted for %s@%s: previous %s, new %s.',
					$appId,
					$version,
					$recordedSha,
					$actualSha,
				);
				$auditMessage = $auditMessage !== null ? $auditMessage . ' ' . $shaAcceptNote : $shaAcceptNote;
			}

			$this->recordInstallAudit($appId, $binding, $installedVersion, $version, AuditLogger::STATUS_SUCCESS, $auditMessage);

			return [
				'status' => 'installed',
				'installedVersionBefore' => $installedVersion === '' ? null : $installedVersion,
				'installedApp' => $installedApp,
				'integrityWarning' => $integrityWarning,
				'dryRun' => false,
				'debug' => $this->debug,
				'binding' => $updatedBinding,
				'recordedShaMatched' => $recordedShaMatched,
				'servedFromCache' => $this->servedFromCache,
			] + ($isDowngrade ? ['orphanedMigrations' => $orphanedMigrations] : []);
		} catch (\Throwable $error) {
			// Best-effort audit write on the failure path, before the exception
			// propagates up to the caller's error mapping; see "Failed install
			// is recorded with the failure reason". Dry runs change nothing, so
			// they are not audited (mirrors the success path above).
			if (!$dryRun) {
				$this->recordInstallAudit($appId, $binding, $installedVersion, $version, AuditLogger::STATUS_FAILURE, null, $error->getMessage());
			}

			throw $error;
		}
	}

	/**
	 * Records one `install` audit entry for the external (GitHub/Codeberg
	 * release) installer, including the integrity-warning text on success;
	 * see "Version operations are recorded".
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	private function recordInstallAudit(
		string $appId,
		SourceBinding $binding,
		string $installedVersionBefore,
		string $requestedVersion,
		string $status,
		?string $integrityWarning = null,
		?string $failureMessage = null,
	): void {
		$actorUid = $this->userSession->getUser()?->getUID() ?? 'system';
		$this->auditLogger->record(
			$actorUid,
			$appId,
			AuditLogger::OPERATION_INSTALL,
			$installedVersionBefore === '' ? null : $installedVersionBefore,
			$requestedVersion,
			$binding->getId(),
			$status,
			$failureMessage ?? $integrityWarning,
		);
	}

	private function resolveAuth(SourceBinding $binding): ?\OCA\Versioniq\Db\Pat {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $this->patResolver->findFor($binding->getForge(), $ownerRepo, $user->getUID());
	}

	private function authenticatedDownload(string $url, string $sinkPath, ?\OCA\Versioniq\Db\Pat $pat): void {
		$options = [
			'sink' => $sinkPath,
			'timeout' => $this->getDownloadTimeout(),
			'headers' => ['User-Agent' => 'Nextcloud-Versioniq'],
			// SSRF defence-in-depth: block fetches to internal addresses even
			// though $url originates from a trusted-source GitHub release JSON.
			// Mirrors PatValidator. See OWASP A10:2021.
			'nextcloud' => ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)],
		];

		if ($pat === null) {
			$this->clientService->newClient()->get($url, $options);

			return;
		}

		$this->patManager->useToken($pat, function (string $token) use ($url, $options): void {
			$options['headers']['Authorization'] = 'Bearer ' . $token;
			$this->clientService->newClient()->get($url, $options);
		});
	}

	/**
	 * Verifies the already-computed `$actualSha` (downloaded archive) against
	 * the release's sibling `.sha256` asset when one exists. This is a
	 * transport check only — see design.md "Trust model: TOFU" for why the
	 * recorded-digest check (above, in the caller) takes precedence.
	 */
	private function verifyChecksum(string $actualSha, ?string $shaUrl, ?\OCA\Versioniq\Db\Pat $pat): ?string {
		if ($shaUrl === null) {
			return 'No SHA-256 checksum available for this artifact.';
		}

		$options = [
			'timeout' => 30,
			'headers' => ['User-Agent' => 'Nextcloud-Versioniq'],
			// SSRF defence-in-depth: same rationale as authenticatedDownload.
			'nextcloud' => ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)],
		];

		try {
			if ($pat === null) {
				$response = $this->clientService->newClient()->get($shaUrl, $options);
			} else {
				$response = $this->patManager->useToken($pat, function (string $token) use ($shaUrl, $options) {
					$options['headers']['Authorization'] = 'Bearer ' . $token;

					return $this->clientService->newClient()->get($shaUrl, $options);
				});
			}
		} catch (Exception $error) {
			$this->logger->warning('External installer: could not fetch .sha256', [
				'shaUrl' => $shaUrl,
				'message' => $error->getMessage(),
			]);

			return 'Failed to fetch advertised SHA-256; install proceeded without verification.';
		}

		if ($response->getStatusCode() !== 200) {
			return 'Failed to fetch advertised SHA-256; install proceeded without verification.';
		}

		$body = trim((string)$response->getBody());
		// Accept both raw hash and `<hash>  <filename>` forms.
		$expected = preg_split('/\s+/', $body)[0] ?? '';
		if (!preg_match('/^[a-f0-9]{64}$/i', $expected)) {
			return 'SHA-256 file format unrecognized; install proceeded without verification.';
		}

		if (!hash_equals(strtolower($expected), $actualSha)) {
			throw new Exception(sprintf(
				'SHA-256 mismatch — expected %s, got %s.',
				strtolower($expected),
				$actualSha
			));
		}

		return null;
	}

	private function extractArchive(string $archiveFile, string $destFolder): string {
		// Try TAR first (most Nextcloud apps publish .tar.gz), fall back to ZIP.
		$archive = new TAR($archiveFile);
		$extracted = $archive->extract($destFolder);
		if (!$extracted) {
			$archive = new ZIP($archiveFile);
			$extracted = $archive->extract($destFolder);
			if (!$extracted) {
				$err = $archive->getError();
				$msg = 'Could not extract release archive (tried TAR and ZIP).';
				if ($err instanceof \PEAR_Error) {
					$msg .= ' ' . $err->getMessage();
				}
				throw new Exception($msg);
			}
		}

		$root = $this->findSingleDirectory($destFolder);
		if ($root === null) {
			throw new Exception('Could not determine extracted app folder.');
		}

		return $root;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parseAndValidateInfoXml(string $extractedRoot, string $expectedAppId, string $expectedVersion): array {
		$infoXml = $extractedRoot . '/appinfo/info.xml';
		$infoContents = @file_get_contents($infoXml);
		if (!is_string($infoContents)) {
			throw new Exception('Downloaded archive is missing appinfo/info.xml.');
		}

		$xml = simplexml_load_string($infoContents);
		if (!$xml instanceof \SimpleXMLElement) {
			throw new Exception('Could not parse appinfo/info.xml from downloaded archive.');
		}

		$archiveAppId = (string)$xml->id;
		$archiveVersion = (string)$xml->version;

		if ($archiveAppId !== $expectedAppId) {
			throw new Exception(sprintf(
				"Downloaded archive declares appId '%s', expected '%s'.",
				$archiveAppId,
				$expectedAppId
			));
		}
		if ($archiveVersion !== $expectedVersion) {
			throw new Exception(sprintf(
				"Downloaded archive declares version '%s', expected '%s'.",
				$archiveVersion,
				$expectedVersion
			));
		}

		$l = $this->l10nFactory->get('core');
		$info = $this->appManager->getAppInfoByPath($infoXml, $l->getLanguageCode());
		if (!is_array($info) || ($info['id'] ?? null) !== $expectedAppId) {
			throw new Exception('appinfo/info.xml could not be loaded by app manager.');
		}
		/** @var array<string, mixed> $info */

		$ignoreMaxApps = (array)$this->config->getSystemValue('app_install_overwrite', []);
		$ignoreMax = in_array($expectedAppId, $ignoreMaxApps, true);
		$serverVersion = $this->serverVersion->getVersionString();
		// \OC_App, not $this->appManager. IAppManager has no isAppCompatible()
		// on Nextcloud 31, so calling it there is a fatal:
		//   Call to undefined method OC\App\AppManager::isAppCompatible()
		// thrown after the archive has been downloaded and hashed, which made
		// every external install fail at the last moment. The legacy static
		// takes the same arguments, and the dependency check on the next line
		// already comes from the same class.
		// \OC_App is private API and carries no OCP stub, so psalm cannot resolve
		// the static. The call is deliberate for the reason above; suppressed
		// here rather than baselined so it stays attached to that reason.
		/** @psalm-suppress UndefinedMethod */
		if (!\OC_App::isAppCompatible($serverVersion, $info, $ignoreMax)) {
			$appName = isset($info['name']) && is_string($info['name']) ? $info['name'] : $expectedAppId;
			throw new Exception(sprintf(
				'App "%s" is not compatible with this Nextcloud version.',
				$appName
			));
		}

		\OC_App::checkAppDependencies($this->config, $l, $info, $ignoreMax);

		return $info;
	}

	private function findSingleDirectory(string $path): ?string {
		$entries = scandir($path);
		if (!is_array($entries)) {
			return null;
		}
		$dirs = array_values(array_filter(
			$entries,
			static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path . '/' . $entry)
		));
		if (count($dirs) !== 1) {
			return null;
		}

		return $path . '/' . $dirs[0];
	}

	private function copyRecursive(string $source, string $destination): void {
		if (!is_dir($source)) {
			throw new Exception('Invalid extracted app source folder.');
		}
		if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
			throw new Exception('Could not create destination folder.');
		}
		$items = scandir($source);
		if (!is_array($items)) {
			throw new Exception('Could not read extracted folder contents.');
		}

		/** @var FilenameValidator $filenameValidator */
		$filenameValidator = $this->container->get(FilenameValidator::class);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$sourceItem = $source . '/' . $item;
			$destinationItem = $destination . '/' . $item;
			if (is_dir($sourceItem)) {
				$this->copyRecursive($sourceItem, $destinationItem);
			} elseif (is_file($sourceItem)) {
				if (!$filenameValidator->isForbidden($sourceItem)) {
					if (!copy($sourceItem, $destinationItem)) {
						throw new Exception('Could not copy app file "' . $item . '".');
					}
				}
			}
		}
	}

	private function getInstallPath(): string {
		foreach (\OC::$APPSROOTS as $dir) {
			if (isset($dir['writable']) && $dir['writable'] === true) {
				if (!is_writable($dir['path']) || !is_readable($dir['path'])) {
					throw new Exception('Cannot write into "apps" directory.');
				}

				return $dir['path'];
			}
		}

		throw new Exception('No writable apps directory found.');
	}

	private function getDownloadTimeout(): int {
		return PHP_SAPI === 'cli' ? 0 : 120;
	}

	/**
	 * Restores the previous app files from the retained backup after a post-swap
	 * failure. Returns whether the restore completed cleanly.
	 */
	private function restoreFromBackup(string $destination, ?string $backupDestination): bool {
		if ($backupDestination === null || !is_dir($backupDestination)) {
			return false;
		}
		try {
			if (is_dir($destination)) {
				$this->rmdirr($destination);
			}

			return rename($backupDestination, $destination);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Recursively deletes a directory on the local filesystem (temp/backup dirs),
	 * replacing the deprecated \OCP\Files::rmdirr helper.
	 */
	private function rmdirr(string $dir): void {
		if (!is_dir($dir)) {
			if (file_exists($dir) || is_link($dir)) {
				@unlink($dir);
			}

			return;
		}

		/** @var \Iterator<string, \SplFileInfo> $iterator */
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			if ($item->isDir() && !$item->isLink()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
	}

	private function resetDebug(): void {
		$this->debug = [];
		$this->lastArchivePath = null;
		$this->lastArchiveMeta = null;
		$this->servedFromCache = false;
	}

	private function addDebug(string $stage, mixed $data = null): void {
		$this->debug[] = ['stage' => $stage, 'data' => $data];
	}
}
