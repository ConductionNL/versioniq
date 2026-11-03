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
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstalledFolder;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Installer\ReleaseTarget;
use OCA\Versioniq\Service\Installer\ReplacesAppFolder;
use OCA\Versioniq\Service\Installer\SignedInstall;
use OCA\Versioniq\Service\Installer\SignedRelease;
use OCA\Versioniq\Service\Installer\SignedReleaseRun;
use OCA\Versioniq\Service\Installer\TempArchive;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;
use phpseclib\File\X509 as X509V2;
use phpseclib3\File\X509 as X509V3;

/**
 * @psalm-api
 */
class SelectedReleaseInstallerService {
	use ReplacesAppFolder;

	/** @var array<int, mixed> */
	private array $debug = [];

	/** @var list<string>|null Set only when the current install is a downgrade; see "Migration diff on downgrade". */
	private ?array $orphanedMigrations = null;

	/** Whether the last {@see replaceWithSelectedRelease()} call was a downgrade. */
	private bool $isDowngradeInstall = false;

	/** Temp path of the archive verified by the last {@see replaceWithSelectedRelease()} call; see "Persist verified artifacts on successful install". */
	private ?string $lastArchivePath = null;

	/** @var ?array{sha256: string, sourceId: ?string, installerKind: string, signature: ?string, certificate: ?string} */
	private ?array $lastArchiveMeta = null;

	/** Whether the last install's archive was served from {@see ArtifactCache} after a download failure; see "Cached fallback with full re-verification". */
	private bool $servedFromCache = false;

	public function __construct(
		private InstallFinalizer $finalizer,
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
		private MigrationDiffer $migrationDiffer,
		private ArtifactCache $artifactCache,
	) {
	}

	/**
	 * Returns internal debug logs for the last operation.
	 *
	 * @return array<int, mixed>
	 */
	public function getDebugLog(): array {
		return $this->debug;
	}

	/**
	 * Returns the migration diff computed for the last downgrade install (null
	 * when the last install was not a downgrade, or when the diff could not be
	 * computed); see "Migration diff on downgrade".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 * @return list<string>|null
	 */
	public function getOrphanedMigrations(): ?array {
		return $this->orphanedMigrations;
	}

	/**
	 * Clears operation debug log.
	 */
	private function resetDebug(): void {
		$this->debug = [];
		$this->orphanedMigrations = null;
		$this->isDowngradeInstall = false;
		$this->lastArchivePath = null;
		$this->lastArchiveMeta = null;
		$this->servedFromCache = false;
	}

	/**
	 * Adds one debug stage entry.
	 *
	 * @param string $stage
	 * @param mixed $data
	 */
	private function addDebug(string $stage, mixed $data = null): void {
		$this->debug[] = [
			'stage' => $stage,
			'data' => $data,
		];
	}

	/**
	 * Splits certificate bundles into individual certificate PEM blocks.
	 *
	 * @param string $cert
	 * @return array<int, string>
	 */
	private function splitCerts(string $cert): array {
		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $cert, $matches);

		return $matches[0];
	}

	/**
	 * Returns an X509 of the phpseclib the server bundles: phpseclib 3 on
	 * Nextcloud 35 and later, phpseclib 2 on Nextcloud 32-34. Both offer the
	 * loadCA, loadX509, loadCRL, validateSignature and getRevoked calls that
	 * verifyCertificate() makes, the same calls core's Installer makes.
	 *
	 * @return X509V3|X509V2
	 * @spec openspec/specs/migration-safety/spec.md#requirement-an-app-store-package-is-verified-against-nextcloud-code-signing-before-install
	 */
	private function newX509(): X509V3|X509V2 {
		if (class_exists(X509V3::class)) {
			return new X509V3();
		}

		return new X509V2();
	}

	/**
	 * Verifies app certificate against Nextcloud signing chain and CRL.
	 *
	 * @param string $appId
	 * @param string $certificate
	 * @throws Exception
	 */
	private function verifyCertificate(string $appId, string $certificate): void {
		$rootCrt = file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crt');
		$rootCrl = file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crl');
		if ($rootCrt === false) {
			throw new Exception('Unable to load Nextcloud root certificate chain.');
		}

		if ($rootCrl === false) {
			throw new Exception('Unable to load Nextcloud certificate revocation list.');
		}

		$x509 = $this->newX509();
		$rootCrtList = $this->splitCerts($rootCrt);
		foreach ($rootCrtList as $rootCertificate) {
			$x509->loadCA($rootCertificate);
		}

		$loadedCertificate = $x509->loadX509($certificate);
		if ($loadedCertificate === false) {
			throw new Exception('Could not parse app certificate.');
		}

		$crl = $this->newX509();
		foreach ($rootCrtList as $rootCertificate) {
			$crl->loadCA($rootCertificate);
		}

		$crl->loadCRL($rootCrl);
		if ($crl->validateSignature() !== true) {
			throw new Exception('Could not validate CRL signature');
		}

		$tbsCertificate = (array)($loadedCertificate['tbsCertificate'] ?? []);
		$serialNumber = $tbsCertificate['serialNumber'] ?? null;
		if (!is_object($serialNumber) || !method_exists($serialNumber, 'toString')) {
			throw new Exception('Could not read certificate serial number.');
		}
		// External-API mismatch: phpseclib X509::loadX509() returns untyped arrays, so the
		// serialNumber BigInteger's ->toString() is not statically known despite the method_exists guard above.
		/** @psalm-suppress MixedMethodCall */
		$serial = (string)$serialNumber->toString();
		$revoked = $crl->getRevoked($serial);
		if ($revoked !== false) {
			throw new Exception(sprintf('Certificate "%s" has been revoked', $serial));
		}

		if ($x509->validateSignature() !== true) {
			throw new Exception(sprintf('App with id %s has a certificate not issued by a trusted Code Signing Authority', $appId));
		}

		$certInfo = openssl_x509_parse($certificate);
		$subject = is_array($certInfo) && is_array($certInfo['subject'] ?? null) ? $certInfo['subject'] : [];
		if (!isset($subject['CN'])) {
			throw new Exception(sprintf('App with id %s has a cert with no CN', $appId));
		}

		$commonName = (string)$subject['CN'];
		if ($commonName !== $appId) {
			throw new Exception(sprintf('App with id %s has a cert issued to %s', $appId, $commonName));
		}

		$this->addDebug('certificate-validated', ['appId' => $appId, 'serial' => $serial]);
	}

	/**
	 * Resolves writable apps root path.
	 *
	 * @return string
	 * @throws Exception
	 */
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

	/**
	 * Returns localization factory.
	 *
	 * @return IFactory
	 */
	private function getL10n(): IFactory {
		return Server::get(IFactory::class);
	}

	/**
	 * Returns app manager.
	 *
	 * @return IAppManager
	 */
	private function getAppManager(): IAppManager {
		return Server::get(IAppManager::class);
	}

	/**
	 * Returns config service.
	 *
	 * @return IConfig
	 */
	private function getConfig(): IConfig {
		return Server::get(IConfig::class);
	}

	/**
	 * Returns app config service.
	 *
	 * @return IAppConfig
	 */
	private function getAppConfig(): IAppConfig {
		return Server::get(IAppConfig::class);
	}

	/**
	 * Returns temp manager.
	 *
	 * @return ITempManager
	 */
	private function getTempManager(): ITempManager {
		return Server::get(ITempManager::class);
	}

	/**
	 * Returns timeout for downloads.
	 *
	 * @return int
	 */
	private function getDownloadTimeout(): int {
		return PHP_SAPI === 'cli' ? 0 : 120;
	}

	/**
	 * Returns HTTP client service.
	 *
	 * @return IClientService
	 */
	private function getClientService(): IClientService {
		return Server::get(IClientService::class);
	}

	/**
	 * Installs one selected App Store release through the signed (code-signing) path; see "Install Specific Version".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param string $appId
	 * @param array<string, mixed> $release
	 * @param bool $dryRun
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	public function installFromSelectedRelease(string $appId, array $release, bool $dryRun = false): array {
		$this->resetDebug();
		$this->addDebug('requested-install', [
			'appId' => $appId,
			'dryRun' => $dryRun,
		]);

		if (!AppIdFormat::isValid($appId)) {
			throw new Exception('Invalid app id.');
		}

		$install = $this->openInstall($appId, $release);

		try {
			$backupDestination = $this->replaceWithSelectedRelease($appId, $release, $dryRun);

			return $dryRun ? $this->dryRunResult($install) : $this->finishInstall($install, $backupDestination);
		} catch (\Throwable $error) {
			// Best-effort audit write on the failure path, before the exception
			// propagates up to the caller's error mapping; see "Failed install
			// is recorded with the failure reason". Dry runs change nothing, so
			// they are not audited (mirrors the success path).
			if (!$dryRun) {
				$this->recordInstallAudit($install, [AuditLogger::STATUS_FAILURE, $error->getMessage()]);
			}

			throw $error;
		}
	}

	/**
	 * Reads the version installed now and the app's enabled value before anything changes.
	 *
	 * @param array<string, mixed> $release
	 */
	private function openInstall(string $appId, array $release): SignedInstall {
		try {
			$installedVersion = $this->getAppManager()->getAppVersion($appId);
		} catch (Exception) {
			$installedVersion = '';
		}

		return new SignedInstall($appId, $release, $installedVersion, $this->getAppConfig()->getValueString($appId, 'enabled', 'no'));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dryRunResult(SignedInstall $install): array {
		$this->addDebug('result', [
			'status' => 'dry-run',
			'message' => 'Skipping installAppLastSteps and post-install writes.',
		]);

		return $this->resultEnvelope($install, null);
	}

	/**
	 * Validates the replaced files, finalizes, drops the backup, persists the
	 * archive and records the success.
	 *
	 * @return array<string, mixed>
	 */
	private function finishInstall(SignedInstall $install, ?string $backupDestination): array {
		$folder = new InstalledFolder($this->getAppManager()->getAppPath($install->appId, true), $backupDestination);
		$this->validateInstalledInfo($install, $folder);
		$this->addDebug('last-steps', [
			'appPath' => $folder->appPath,
			'enabled' => $install->enabledAfter(),
		]);

		$installedApp = $this->finalizeInstall($install, $folder);

		// Finalize succeeded — now it is safe to drop the backup.
		if ($folder->backup !== null && is_dir($folder->backup)) {
			$this->rmdirr($folder->backup);
		}
		$this->addDebug('post-install-state', [
			'appPath' => $folder->appPath,
			'installedVersionConfig' => $this->getAppConfig()->getValueString($install->appId, 'installed_version', ''),
			'installedApp' => $installedApp,
		]);
		$this->addDebug('installed', ['appId' => $installedApp]);

		$this->persistArchive($install);
		$this->recordInstallAudit($install, [AuditLogger::STATUS_SUCCESS, null]);

		return $this->resultEnvelope($install, $installedApp);
	}

	/**
	 * Pre-finalize validation (appinfo readable, compatible, deps met). On a
	 * failure the files are restored from the retained backup and the outcome
	 * is a clean revert: the previous version is intact.
	 */
	private function validateInstalledInfo(SignedInstall $install, InstalledFolder $folder): void {
		$appManager = $this->getAppManager();
		$config = $this->getConfig();
		$l = $this->getL10n()->get('core');
		$appId = $install->appId;

		try {
			$info = $appManager->getAppInfoByPath($folder->appPath . '/appinfo/info.xml', $l->getLanguageCode());
			if (!is_array($info) || ($info['id'] ?? null) !== $appId) {
				throw new Exception(
					$l->t('App "%s" cannot be installed because appinfo file cannot be read.',
						[$appId]
					)
				);
			}
			/** @var array<string, mixed> $info */

			$ignoreMaxApps = (array)$config->getSystemValue('app_install_overwrite', []);
			$ignoreMax = in_array($appId, $ignoreMaxApps, true);
			$serverVersion = Server::get(\OCP\ServerVersion::class)->getVersionString();
			if (!$appManager->isAppCompatible($serverVersion, $info, $ignoreMax)) {
				$appName = isset($info['name']) && is_string($info['name']) ? $info['name'] : $appId;
				throw new Exception(
					$l->t('App "%s" cannot be installed because it is not compatible with this version of the server.',
						[$appName]
					)
				);
			}

			\OC_App::checkAppDependencies($config, $l, $info, $ignoreMax);
		} catch (Exception $validationError) {
			$this->restoreFromBackup($folder->appPath, $folder->backup);
			throw InstallFailure::reverted($validationError->getMessage(), FailureClassifier::STAGE_INFO_VALIDATED, $validationError);
		}

		$folder->info = $info;
	}

	/**
	 * Finalize (migrations + repair steps) is the last, unrecoverable phase.
	 * On a failure the previous files are restored and the outcome is
	 * installed-but-broken. Throwable, not just Exception: a finalize-phase
	 * Error must still restore the previous files, not surface as an
	 * uncaught fatal.
	 */
	private function finalizeInstall(SignedInstall $install, InstalledFolder $folder): string {
		try {
			return $this->finalizer->finalize($folder->appPath, $folder->info, $install->enabledAfter(), null, SourceBinding::appStore()->getId());
		} catch (\Throwable $finalizeError) {
			$restoreState = $folder->backup === null
				? FailureClassifier::RESTORE_NONE
				: ($this->restoreFromBackup($folder->appPath, $folder->backup) ? FailureClassifier::RESTORE_CLEAN : FailureClassifier::RESTORE_FAILED);
			throw InstallFailure::finalizeFailed($finalizeError->getMessage(), $restoreState, $finalizeError);
		}
	}

	/**
	 * Persists the just-verified archive for future rollback; see "Persist
	 * verified artifacts on successful install". Only reached after
	 * finalize() succeeded, so a failed install never populates the cache.
	 */
	private function persistArchive(SignedInstall $install): void {
		$requestedVersion = $install->requestedVersion();
		if ($requestedVersion !== null && $this->lastArchivePath !== null && $this->lastArchiveMeta !== null) {
			$this->artifactCache->store($install->appId, $requestedVersion, $this->lastArchivePath, $this->lastArchiveMeta);
		}
	}

	/**
	 * The envelope returned for a dry run (no installed app) or a completed install.
	 *
	 * @return array<string, mixed>
	 */
	private function resultEnvelope(SignedInstall $install, ?string $installedApp): array {
		$result = [
			'status' => $installedApp === null ? 'dry-run' : 'installed',
			'installedVersionBefore' => $install->fromVersion(),
		];
		if ($installedApp !== null) {
			$result['installedApp'] = $installedApp;
		}

		return $result + [
			'dryRun' => $installedApp === null,
			'debug' => $this->debug,
			'servedFromCache' => $this->servedFromCache,
		] + ($this->isDowngradeInstall ? ['orphanedMigrations' => $this->orphanedMigrations] : []);
	}

	/**
	 * Records one `install` audit entry for the App Store (signed) installer;
	 * see "Version operations are recorded".
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @param array{0: string, 1: ?string} $outcome Status and message.
	 */
	private function recordInstallAudit(SignedInstall $install, array $outcome): void {
		$actorUid = $this->userSession->getUser()?->getUID() ?? 'system';
		$this->auditLogger->record(
			new AuditSubject($actorUid, $install->appId, AuditLogger::OPERATION_INSTALL),
			new AuditedVersions($install->fromVersion(), $install->requestedVersion(), SourceBinding::appStore()->getId()),
			$outcome[0],
			$outcome[1],
		);
	}

	/**
	 * Verifies signature/certificate, downloads, validates appId+version, and replaces app files (with backup/restore);
	 * see "Install Specific Version" ("Installation fails" — no partial installs).
	 *
	 * Returns the retained backup path (or null when there was no previous
	 * install / on dry run); the caller deletes it after `finalize()` succeeds
	 * or restores from it on a finalize-phase failure.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param string $appId
	 * @param array<string, mixed> $release
	 * @param bool $dryRun
	 * @return ?string
	 * @throws Exception
	 */
	public function replaceWithSelectedRelease(string $appId, array $release, bool $dryRun): ?string {
		$signed = SignedRelease::fromRelease($release);
		$this->addDebug('release-metadata', [
			'downloadUrl' => $signed->download,
			'expectedVersion' => $signed->version,
			'hasSignature' => true,
			'hasCertificate' => true,
			'dryRun' => $dryRun,
		]);

		$this->verifyCertificate($appId, $signed->certificate);

		$run = new SignedReleaseRun($appId, $signed, $this->allocateTempArchive());
		$this->downloadRelease($run);
		$target = $this->resolveTarget($run, $this->extractRelease($run));
		$this->diffDowngrade($run, $target);

		$backupDestination = $this->backupExistingFolder($target->destination, $dryRun, $target->previousPath !== null);
		if ($dryRun) {
			return null;
		}
		$this->copyIntoPlace($target, $backupDestination);

		// Backup is intentionally retained until finalize() succeeds; the caller
		// owns its deletion (success) or restore (finalize-phase failure).
		return $backupDestination;
	}

	private function allocateTempArchive(): TempArchive {
		$tempManager = $this->getTempManager();
		$tempFile = $tempManager->getTemporaryFile('.tar.gz');
		$tempFolder = $tempManager->getTemporaryFolder('app-version');
		if (!is_string($tempFile) || !is_string($tempFolder)) {
			throw new Exception('Could not allocate temporary download paths.');
		}

		return new TempArchive($tempFile, $tempFolder);
	}

	private function downloadRelease(SignedReleaseRun $run): void {
		try {
			$client = $this->getClientService()->newClient();
			$client->get($run->release->download, [
				'sink' => $run->temp->file,
				'timeout' => $this->getDownloadTimeout(),
			]);
		} catch (Exception $error) {
			// Download failed (network error, dead URL, deleted release):
			// fall back to a cached artifact for this exact appId+version, if
			// one exists — see "Cached fallback with full re-verification".
			$cached = $this->cachedRelease($run);
			if ($cached === null) {
				throw new Exception('Could not download selected release: ' . $error->getMessage());
			}
			$run->release = $cached;
			$this->servedFromCache = true;
			$this->addDebug('served-from-cache', ['appId' => $run->appId, 'version' => $run->release->version]);
		}
		$this->addDebug('downloaded', ['tempFile' => $run->temp->file, 'servedFromCache' => $this->servedFromCache]);
	}

	/**
	 * Writes the cached artifact for the app and version to the temp file and
	 * returns the release with the cached certificate and signature, so the
	 * signature check re-verifies against what was actually cached, not
	 * merely what this request passed in. Null when there is no usable cache
	 * entry or its certificate does not verify.
	 */
	private function cachedRelease(SignedReleaseRun $run): ?SignedRelease {
		$cached = $this->artifactCache->fetch($run->appId, $run->release->version);
		if ($cached === null) {
			return null;
		}
		$materials = $this->verifiedMaterials($run->appId, $cached['meta']);
		if ($materials === null) {
			return null;
		}
		if (file_put_contents($run->temp->file, $cached['content']) === false) {
			return null;
		}

		return $run->release->withStoredMaterials($materials['certificate'], $materials['signature']);
	}

	/**
	 * The certificate and signature stored with a cached archive, when both
	 * are strings and the certificate still verifies for the app.
	 *
	 * @param array<string, mixed> $meta
	 * @return ?array{certificate: string, signature: string}
	 */
	private function verifiedMaterials(string $appId, array $meta): ?array {
		$certificate = $meta['certificate'] ?? null;
		$signature = $meta['signature'] ?? null;
		if (!is_string($certificate) || !is_string($signature)) {
			return null;
		}
		try {
			$this->verifyCertificate($appId, $certificate);
		} catch (Exception) {
			return null;
		}

		return ['certificate' => $certificate, 'signature' => $signature];
	}
	/**
	 * Extracts the archive, checks its info.xml against the request and
	 * verifies the release signature; returns the extracted app root.
	 */
	private function extractRelease(SignedReleaseRun $run): string {
		$archive = new TAR($run->temp->file);
		if (!$archive->extract($run->temp->folder)) {
			$errorMessage = 'Could not extract selected app archive';
			$archiveError = $archive->getError();
			if ($archiveError instanceof \PEAR_Error) {
				$errorMessage .= ': ' . $archiveError->getMessage();
			}

			throw new Exception($errorMessage);
		}
		$this->addDebug('archive-extracted', ['tempFolder' => $run->temp->folder]);

		$extractedRoot = $this->findSingleDirectory($run->temp->folder);
		if ($extractedRoot === null) {
			throw new Exception('Could not determine extracted app folder.');
		}
		$this->addDebug('extracted-root', ['path' => $extractedRoot]);

		$this->checkInfoXml($run, $extractedRoot);
		$this->verifySignature($run);

		return $extractedRoot;
	}

	private function checkInfoXml(SignedReleaseRun $run, string $extractedRoot): void {
		$infoContents = file_get_contents($extractedRoot . '/appinfo/info.xml');
		if (!is_string($infoContents)) {
			throw new Exception('Could not read appinfo/info.xml from selected release.');
		}

		$info = simplexml_load_string($infoContents);
		if (!$info instanceof \SimpleXMLElement) {
			throw new Exception('Could not parse appinfo/info.xml from selected release.');
		}

		if ((string)$info->id !== $run->appId) {
			throw new Exception('Downloaded app id does not match requested app.');
		}

		$archiveVersion = (string)$info->version;
		if ($run->release->version !== $archiveVersion) {
			throw new Exception('Downloaded app version does not match requested version.');
		}
		$this->addDebug('info-xml', [
			'appId' => (string)$info->id,
			'archiveVersion' => $archiveVersion,
		]);
	}

	/**
	 * Verifies the downloaded bytes against the release signature and keeps
	 * them for ArtifactCache::store() once finalize() succeeds; see "Persist
	 * verified artifacts on successful install". The temp file still holds
	 * exactly the bytes that pass here (fresh download or, on a cache
	 * fallback, the re-verified cached copy).
	 */
	private function verifySignature(SignedReleaseRun $run): void {
		$publicKey = openssl_get_publickey($run->release->certificate);
		if ($publicKey === false) {
			throw new Exception('Could not read appstore certificate.');
		}

		$downloadedContent = file_get_contents($run->temp->file);
		if (!is_string($downloadedContent)) {
			throw new Exception('Could not read downloaded archive for signature verification.');
		}

		$signatureData = base64_decode($run->release->signature, true);
		if (!is_string($signatureData)) {
			throw new Exception('Could not decode release signature.');
		}

		if (openssl_verify($downloadedContent, $signatureData, $publicKey, OPENSSL_ALGO_SHA512) !== 1) {
			throw new Exception('Release signature verification failed.');
		}
		$this->addDebug('signature-verified', ['result' => 'ok']);

		$this->lastArchivePath = $run->temp->file;
		$this->lastArchiveMeta = [
			'sha256' => strtolower(hash('sha256', $downloadedContent)),
			'sourceId' => SourceBinding::appStore()->getId(),
			'installerKind' => SourceInterface::INSTALLER_SIGNED,
			'signature' => $run->release->signature,
			'certificate' => $run->release->certificate,
		];
	}

	private function resolveTarget(SignedReleaseRun $run, string $extractedRoot): ReleaseTarget {
		try {
			$previousPath = $this->getAppManager()->getAppPath($run->appId);
		} catch (AppPathNotFoundException) {
			$previousPath = null;
		}

		$destination = $previousPath ?? $this->getInstallPath() . '/' . $run->appId;

		if (!is_dir(dirname($destination))) {
			throw new Exception('Could not resolve app install folder.');
		}
		$this->addDebug('destination', ['destination' => $destination]);

		return new ReleaseTarget($extractedRoot, $previousPath, $destination);
	}

	/**
	 * Migration diff (downgrade only, acknowledged or dry-run): compares the
	 * still-in-place installed copy's migration steps against the
	 * just-extracted target archive before any file swap — see "Migration
	 * diff on downgrade". A diff failure degrades to `null` (generic
	 * warning); it never blocks the downgrade itself.
	 */
	private function diffDowngrade(SignedReleaseRun $run, ReleaseTarget $target): void {
		try {
			$installedVersion = $this->getAppManager()->getAppVersion($run->appId);
		} catch (Exception) {
			$installedVersion = '';
		}
		if ($installedVersion !== '' && version_compare($run->release->version, $installedVersion, '<')) {
			$this->isDowngradeInstall = true;
			$this->orphanedMigrations = $this->migrationDiffer->diff($target->previousPath, $target->root);
			$this->addDebug('migration-diff', ['orphanedMigrations' => $this->orphanedMigrations]);
		}
	}

	/**
	 * Copies the extracted release into place. A failure here is
	 * pre-finalize: the previous files are restored and the outcome is a
	 * clean revert. For a fresh install (no backup) the partially-copied
	 * files are removed so no broken app folder is left behind.
	 */
	private function copyIntoPlace(ReleaseTarget $target, ?string $backupDestination): void {
		$destination = $target->destination;
		try {
			if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
				throw new Exception('Could not create app destination folder.');
			}
			$this->copyRecursive($target->root, $destination);
		} catch (Exception $error) {
			$this->revertCopy($destination, $backupDestination);
			throw InstallFailure::reverted($error->getMessage(), 'copy', $error);
		}
		$this->markFilesystemUpdated($destination);
	}

	/**
	 * Moves the live app folder aside before the new files are copied in.
	 *
	 * @return ?string The backup path, or null when there was nothing to move
	 *                 or the run is a dry run.
	 * @throws Exception When the live folder cannot be moved aside.
	 */
	private function backupExistingFolder(string $destination, bool $dryRun, bool $hasExistingAppPath): ?string {
		// A dry run returns BEFORE the live folder is touched (#427). It used
		// to rename the folder aside and back, and the rename back was never
		// checked, so a failure there left the app folder missing.
		if ($dryRun) {
			$this->addDebug('dry-run-skip-filesystem', [
				'message' => 'Skipping backup, copy/replace, and cleanup.',
				'hasExistingAppPath' => $hasExistingAppPath,
			]);
			return null;
		}

		return $this->backupExisting($destination);
	}
}
