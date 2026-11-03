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
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\AppIdFormat;
use OCA\Versioniq\Service\Installer\ArchiveCheck;
use OCA\Versioniq\Service\Installer\ArchiveDownload;
use OCA\Versioniq\Service\Installer\ExternalInstall;
use OCA\Versioniq\Service\Installer\ExternalInstallOptions;
use OCA\Versioniq\Service\Installer\ExternalInstallRequest;
use OCA\Versioniq\Service\Installer\ExternalInstallRun;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use OCA\Versioniq\Service\Installer\InstallFailure;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\InstallSteps;
use OCA\Versioniq\Service\Installer\ReleaseDownload;
use OCA\Versioniq\Service\Installer\ReplacesAppFolder;
use OCA\Versioniq\Service\Installer\ShaMismatchException;
use OCA\Versioniq\Service\Installer\StagedRelease;
use OCA\Versioniq\Service\Pat\PatAccess;
use OCA\Versioniq\Service\Settings\ConfigStores;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceInterface;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IResponse;
use OCP\L10N\IFactory;
use OCP\Server;
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
	use ReplacesAppFolder;

	/** @var list<array{stage: string, data: mixed}> */
	private array $debug = [];

	/** Temp path of the archive verified by the current install; see "Persist verified artifacts on successful install". */
	private ?string $lastArchivePath = null;

	/** @var ?array{sha256: string, sourceId: ?string, installerKind: string} */
	private ?array $lastArchiveMeta = null;

	/** Whether the current install's archive was served from {@see ArtifactCache} after a download failure; see "Cached fallback with full re-verification". */
	private bool $servedFromCache = false;

	public function __construct(
		private IAppManager $appManager,
		private ConfigStores $configs,
		private ArchiveDownload $download,
		private InstallSteps $steps,
		private PatAccess $pats,
		private LoggerInterface $logger,
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
		$request = new ExternalInstallRequest($appId, $version, $release, new ExternalInstallOptions($dryRun, $acceptNewSha));
		$this->beginRun($request, $binding);
		$run = new ExternalInstallRun($request, $binding, $this->installedVersionOf($appId));

		try {
			return $this->runInstall($run);
		} catch (\Throwable $error) {
			$this->auditFailure($run, $error);

			throw $error;
		}
	}

	private function beginRun(ExternalInstallRequest $request, SourceBinding $binding): void {
		$this->resetDebug();
		$this->addDebug('requested-install', [
			'appId' => $request->appId,
			'version' => $request->version,
			'sourceId' => $binding->getId(),
			'dryRun' => $request->options->dryRun,
		]);
	}

	/**
	 * Best-effort audit write on the failure path, before the exception
	 * propagates up to the caller's error mapping; see "Failed install is
	 * recorded with the failure reason". Dry runs change nothing, so they are
	 * not audited (mirrors the success path).
	 */
	private function auditFailure(ExternalInstallRun $run, \Throwable $error): void {
		if (!$run->request->options->dryRun) {
			$this->recordInstallAudit($run, [AuditLogger::STATUS_FAILURE, $error->getMessage()]);
		}
	}

	/**
	 * The version the app manager reports for `$appId`, or an empty string when it is not installed.
	 */
	private function installedVersionOf(string $appId): string {
		try {
			return $this->appManager->getAppVersion($appId);
		} catch (Exception) {
			return '';
		}
	}

	/**
	 * Runs the install steps in order: guards, download and integrity checks,
	 * staging, then either the dry-run report or the file swap and finalize.
	 *
	 * @return array{status: string, installedVersionBefore: ?string, installedApp?: string, integrityWarning: ?string, dryRun: bool, debug: list<array{stage: string, data: mixed}>, binding: SourceBinding, recordedShaMatched: bool, servedFromCache: bool, orphanedMigrations?: list<string>|null}
	 */
	private function runInstall(ExternalInstallRun $run): array {
		$this->assertInstallable($run);
		$download = $this->prepareDownload($run);
		$check = $this->fetchVerifiedArchive($run, $download);
		$staged = $this->stageRelease($run, $download);
		$install = new ExternalInstall($run, $check, $staged, $this->orphanedMigrations($run, $staged));

		if ($run->request->options->dryRun) {
			$this->addDebug('dry-run-skip-filesystem', ['destination' => $staged->destination]);

			return $this->resultEnvelope($install, ['binding' => $run->binding]);
		}

		return $this->swapAndFinalize($install);
	}

	/**
	 * Rejects a malformed app id and a source outside the trusted-source allowlist.
	 */
	private function assertInstallable(ExternalInstallRun $run): void {
		if (!AppIdFormat::isValid($run->request->appId)) {
			throw new Exception('Invalid app id.');
		}

		$this->steps->trustedSources->assertBindingAllowed($run->binding);
	}

	/**
	 * Checks the release has an archive URL, reads the app's enabled value and
	 * allocates the temporary paths, then resolves the stored token.
	 */
	private function prepareDownload(ExternalInstallRun $run): ReleaseDownload {
		if ($run->request->downloadUrl() === '') {
			throw new Exception('No download URL found for the selected release.');
		}

		$run->previousEnabled = $this->configs->appConfig->getValueString($run->request->appId, 'enabled', 'no');

		$tempFile = $this->download->tempManager->getTemporaryFile('.tar.gz');
		$tempFolder = $this->download->tempManager->getTemporaryFolder('app-version-external');
		if (!is_string($tempFile) || !is_string($tempFolder)) {
			throw new Exception('Could not allocate temporary download paths.');
		}

		$token = $this->resolveAuth($run->binding);
		$this->addDebug('auth-resolution', ['hasPat' => $token !== null]);

		return new ReleaseDownload($tempFile, $tempFolder, $token);
	}

	/**
	 * Downloads the archive (or falls back to the cache), hashes it once, and
	 * checks the hash against the recorded digest and the sibling `.sha256`.
	 */
	private function fetchVerifiedArchive(ExternalInstallRun $run, ReleaseDownload $download): ArchiveCheck {
		$this->downloadArchive($run, $download);

		// Hash the downloaded archive exactly once; both the recorded-digest
		// (TOFU) check and the sibling `.sha256` verification reuse this
		// value — see design.md "Enforcement point".
		$actualSha = $this->hashArchive($download->tempFile);
		$recordedShaMatched = $this->checkRecordedSha($run, $actualSha);

		$shaUrl = $run->request->shaUrl();
		$integrityWarning = $this->verifyChecksum(
			$actualSha,
			$shaUrl,
			$shaUrl === null ? null : $this->patForUrl($shaUrl, $run->binding, $download->token),
		);
		$this->addDebug('checksum', ['shaUrl' => $shaUrl, 'integrityWarning' => $integrityWarning]);

		// Captured for persisting via ArtifactCache::store() once finalize()
		// succeeds — see "Persist verified artifacts on successful install".
		$this->lastArchivePath = $download->tempFile;
		$this->lastArchiveMeta = [
			'sha256' => $actualSha,
			'sourceId' => $run->binding->getId(),
			'installerKind' => SourceInterface::INSTALLER_EXTERNAL,
		];

		return new ArchiveCheck($actualSha, $recordedShaMatched, $integrityWarning);
	}

	private function downloadArchive(ExternalInstallRun $run, ReleaseDownload $download): void {
		$downloadUrl = $run->request->downloadUrl();
		try {
			$this->authenticatedDownload($downloadUrl, $download->tempFile, $this->patForUrl($downloadUrl, $run->binding, $download->token));
		} catch (Exception $error) {
			// Download failed (network error, dead URL, deleted release
			// asset): fall back to a cached artifact for this exact
			// appId+version, if one exists — see "Cached fallback with
			// full re-verification". The allowlist gate already ran, so a
			// cache hit for an untrusted source is unreachable here. The
			// recorded-SHA (TOFU) and sibling `.sha256` checks run against
			// the cache-served bytes exactly as they would a fresh download.
			if (!$this->restoreFromCache($run->request, $download->tempFile)) {
				throw new Exception('Could not download selected release: ' . $error->getMessage());
			}
		}
		$this->addDebug('downloaded', ['tempFile' => $download->tempFile, 'sourceUrl' => $downloadUrl, 'servedFromCache' => $this->servedFromCache]);
	}

	/**
	 * Writes the cached artifact for the requested app and version to `$tempFile`; false when there is none.
	 */
	private function restoreFromCache(ExternalInstallRequest $request, string $tempFile): bool {
		$cached = $this->download->artifactCache->fetch($request->appId, $request->version);
		if ($cached === null || file_put_contents($tempFile, $cached['content']) === false) {
			return false;
		}
		$this->servedFromCache = true;
		$this->addDebug('served-from-cache', ['appId' => $request->appId, 'version' => $request->version]);

		return true;
	}

	private function hashArchive(string $tempFile): string {
		$actualSha = hash_file('sha256', $tempFile);
		if ($actualSha === false) {
			throw new Exception('Could not compute SHA-256 of downloaded archive.');
		}

		return strtolower($actualSha);
	}

	/**
	 * Trust-on-first-use enforcement: a digest recorded from a previous
	 * successful install of this (appId, version, source) outranks whatever
	 * the source serves now, including a co-published, possibly-rewritten
	 * `.sha256` sibling — see "Recorded SHA-256 enforced on reinstall".
	 * Checked before extraction/backup: no filesystem change happens on
	 * mismatch. Returns whether the recorded digest matched.
	 */
	private function checkRecordedSha(ExternalInstallRun $run, string $actualSha): bool {
		$recordedSha = $run->recordedSha();
		$recordedShaMatched = $recordedSha !== null && hash_equals($recordedSha, $actualSha);
		if ($recordedSha !== null && !$run->request->options->acceptNewSha && !$recordedShaMatched) {
			throw new ShaMismatchException($run->request->appId, $run->request->version, $recordedSha, $actualSha);
		}
		$this->addDebug('recorded-sha-check', [
			'recordedSha' => $recordedSha,
			'actualSha' => $actualSha,
			'matched' => $recordedShaMatched,
			'acceptNewSha' => $run->request->options->acceptNewSha,
		]);

		return $recordedShaMatched;
	}

	/**
	 * Extracts the archive, validates its app info and resolves the folder the app goes to.
	 */
	private function stageRelease(ExternalInstallRun $run, ReleaseDownload $download): StagedRelease {
		$archivePath = $this->extractArchive($download->tempFile, $download->tempFolder);
		$this->addDebug('archive-extracted', ['extractedRoot' => $archivePath]);

		$info = $this->parseAndValidateInfoXml($archivePath, $run->request->appId, $run->request->version);
		$this->addDebug('info-validated', [
			'appId' => $info['id'],
			'archiveVersion' => $info['version'],
		]);

		$previousPath = $this->previousAppPath($run->request->appId);
		$destination = $previousPath ?? $this->getInstallPath() . '/' . $run->request->appId;

		if (!is_dir(dirname($destination))) {
			throw new Exception('Could not resolve app install folder.');
		}

		return new StagedRelease($archivePath, $info, $previousPath, $destination);
	}

	private function previousAppPath(string $appId): ?string {
		try {
			return $this->appManager->getAppPath($appId);
		} catch (AppPathNotFoundException) {
			return null;
		}
	}

	/**
	 * Migration diff (downgrade only, acknowledged or dry-run): compares the
	 * installed copy's migration steps against the just-extracted target
	 * archive before any file swap — see "Migration diff on downgrade". A diff
	 * failure degrades to `null` (generic warning); it never blocks the
	 * downgrade itself.
	 *
	 * @return list<string>|null
	 */
	private function orphanedMigrations(ExternalInstallRun $run, StagedRelease $staged): ?array {
		if (!$run->isDowngrade()) {
			return null;
		}
		$orphanedMigrations = $this->steps->migrationDiffer->diff($staged->previousPath, $staged->root);
		$this->addDebug('migration-diff', ['orphanedMigrations' => $orphanedMigrations]);

		return $orphanedMigrations;
	}

	/**
	 * Swaps the app files, finalizes, drops the backup, persists the archive and records the success.
	 *
	 * @return array{status: string, installedVersionBefore: ?string, installedApp?: string, integrityWarning: ?string, dryRun: bool, debug: list<array{stage: string, data: mixed}>, binding: SourceBinding, recordedShaMatched: bool, servedFromCache: bool, orphanedMigrations?: list<string>|null}
	 */
	private function swapAndFinalize(ExternalInstall $install): array {
		$backupDestination = $this->backupExisting($install->staged->destination);
		$this->copyIntoPlace($install->staged, $backupDestination);
		$installedApp = $this->finalizeInstall($install, $backupDestination);

		// Finalize succeeded — now it is safe to drop the backup.
		if ($backupDestination !== null && is_dir($backupDestination)) {
			$this->rmdirr($backupDestination);
		}
		$this->addDebug('finalized', ['appId' => $installedApp, 'enabled' => $install->run->enabledAfter()]);

		// Persist the just-verified archive for future rollback; see
		// "Persist verified artifacts on successful install". Only reached
		// after finalize() succeeded, so a failed install never populates
		// the cache.
		if ($this->lastArchivePath !== null && $this->lastArchiveMeta !== null) {
			$this->download->artifactCache->store($install->run->request->appId, $install->run->request->version, $this->lastArchivePath, $this->lastArchiveMeta);
		}

		// Record the observed digest only now that the install fully
		// succeeded (never on a failure path) — see "SHA-256 recorded on
		// first successful external install". Sibling-verified or locally
		// computed, the value is the same: verifyChecksum() already threw if
		// a sibling digest existed and disagreed with the computed one.
		$updatedBinding = $install->run->binding->withRecordedSha($install->run->request->version, $install->check->sha);
		$this->recordInstallAudit($install->run, [AuditLogger::STATUS_SUCCESS, $this->successAuditMessage($install)]);

		return $this->resultEnvelope($install, ['binding' => $updatedBinding, 'installedApp' => $installedApp]);
	}

	/**
	 * Copies the staged release into its folder. A failure here is pre-finalize:
	 * the previous files are restored and the outcome is a clean revert. For a
	 * fresh install (no backup) the partially-copied files are removed so no
	 * broken app folder is left behind.
	 */
	private function copyIntoPlace(StagedRelease $staged, ?string $backupDestination): void {
		$destination = $staged->destination;
		try {
			if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
				throw new Exception('Could not create app destination folder.');
			}
			$this->copyRecursive($staged->root, $destination);
		} catch (Exception $error) {
			$this->revertCopy($destination, $backupDestination);
			throw InstallFailure::reverted($error->getMessage(), 'copy', $error);
		}
		$this->markFilesystemUpdated($destination);
	}

	/**
	 * Finalize (migrations + repair steps) is the last, unrecoverable phase.
	 * On a failure the previous files are restored and the outcome is
	 * installed-but-broken. Throwable, not just Exception: a finalize-phase
	 * Error (e.g. a core API removed between releases) must still restore the
	 * previous files, never surface as an uncaught fatal that leaves the app
	 * half-swapped.
	 */
	private function finalizeInstall(ExternalInstall $install, ?string $backupDestination): string {
		$destination = $install->staged->destination;
		try {
			return $this->steps->finalizer->finalize($destination, $install->staged->info, $install->run->enabledAfter(), null, $install->run->binding->getId());
		} catch (\Throwable $finalizeError) {
			$restoreState = $backupDestination === null
				? FailureClassifier::RESTORE_NONE
				: ($this->restoreFromBackup($destination, $backupDestination) ? FailureClassifier::RESTORE_CLEAN : FailureClassifier::RESTORE_FAILED);
			throw InstallFailure::finalizeFailed($finalizeError->getMessage(), $restoreState, $finalizeError);
		}
	}

	/**
	 * The audit message of a successful install: the integrity warning, plus a
	 * note (and a logged warning) when a new SHA-256 replaced a recorded one.
	 */
	private function successAuditMessage(ExternalInstall $install): ?string {
		$integrityWarning = $install->check->integrityWarning;
		$recordedSha = $install->run->recordedSha();
		if ($recordedSha === null || $install->check->recordedShaMatched) {
			return $integrityWarning;
		}

		$appId = $install->run->request->appId;
		$version = $install->run->request->version;
		$actualSha = $install->check->sha;
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

		return $integrityWarning !== null ? $integrityWarning . ' ' . $shaAcceptNote : $shaAcceptNote;
	}

	/**
	 * The envelope returned for a dry run (no `installedApp`) or a completed install.
	 *
	 * @param array{binding: SourceBinding, installedApp?: string} $outcome
	 * @return array{status: string, installedVersionBefore: ?string, installedApp?: string, integrityWarning: ?string, dryRun: bool, debug: list<array{stage: string, data: mixed}>, binding: SourceBinding, recordedShaMatched: bool, servedFromCache: bool, orphanedMigrations?: list<string>|null}
	 */
	private function resultEnvelope(ExternalInstall $install, array $outcome): array {
		$dryRun = !isset($outcome['installedApp']);
		$result = [
			'status' => $dryRun ? 'dry-run' : 'installed',
			'installedVersionBefore' => $install->run->fromVersion(),
		];
		if (isset($outcome['installedApp'])) {
			$result['installedApp'] = $outcome['installedApp'];
		}

		return $result + [
			'integrityWarning' => $install->check->integrityWarning,
			'dryRun' => $dryRun,
			'debug' => $this->debug,
			'binding' => $outcome['binding'],
			'recordedShaMatched' => $install->check->recordedShaMatched,
			'servedFromCache' => $this->servedFromCache,
		] + ($install->run->isDowngrade() ? ['orphanedMigrations' => $install->orphanedMigrations] : []);
	}

	/**
	 * Records one `install` audit entry for the external (GitHub/Codeberg
	 * release) installer: the failure reason, or on success the integrity
	 * warning; see "Version operations are recorded".
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @param array{0: string, 1: ?string} $outcome Status and message.
	 */
	private function recordInstallAudit(ExternalInstallRun $run, array $outcome): void {
		$actorUid = $this->pats->userSession->getUser()?->getUID() ?? 'system';
		$this->steps->auditLogger->record(
			new AuditSubject($actorUid, $run->request->appId, AuditLogger::OPERATION_INSTALL),
			new AuditedVersions($run->fromVersion(), $run->request->version, $run->binding->getId()),
			$outcome[0],
			$outcome[1],
		);
	}

	private function resolveAuth(SourceBinding $binding): ?\OCA\Versioniq\Db\Pat {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}
		// No session user means AutoUpdateJob; the resolver then picks from
		// tokens shared with admins instead of downloading anonymously (#430).
		return $this->pats->patResolver->findFor($binding->getForge(), $ownerRepo, $this->pats->userSession->getUser()?->getUID());
	}

	/**
	 * The stored token, only when the URL is https on the binding's own forge
	 * host and port.
	 *
	 * The asset and checksum URLs come from the release JSON, and a forge that
	 * lets a release link an external asset would otherwise hand that host the
	 * token. The URL must be https, and its host and port must equal
	 * the forge's web or API address exactly, so a lookalike such as
	 * `github.com.example.org` does not qualify. Anything else is fetched
	 * anonymously. The HTTP client's own redirect handling
	 * already drops the header when a redirect changes host.
	 *
	 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.3
	 */
	private function patForUrl(string $url, SourceBinding $binding, ?\OCA\Versioniq\Db\Pat $pat): ?\OCA\Versioniq\Db\Pat {
		if ($pat === null) {
			return null;
		}

		$host = strtolower((string)parse_url($url, PHP_URL_HOST));
		if ($host === '' || !$this->download->forgeRegistry->has($binding->getForge())) {
			return null;
		}

		$forge = $this->download->forgeRegistry->get($binding->getForge());
		$forgeAddresses = array_filter([self::httpsAddress($forge->webBaseUrl), self::httpsAddress($forge->apiBaseUrl)]);
		if (in_array(self::httpsAddress($url), $forgeAddresses, true)) {
			return $pat;
		}

		$this->logger->info('External installer: the stored {forge} token goes only over https to that forge\'s own host and port, so {host} does not get it.', [
			'host' => $host,
			'forge' => $forge->id,
		]);

		return null;
	}

	/**
	 * An https URL's lower-cased host and port (443 when it names none), or
	 * null for any other scheme or a URL without a host.
	 */
	private static function httpsAddress(string $url): ?string {
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
			return null;
		}

		return strtolower($parts['host']) . ':' . ($parts['port'] ?? 443);
	}

	private function authenticatedDownload(string $url, string $sinkPath, ?\OCA\Versioniq\Db\Pat $pat): void {
		$options = [
			'sink' => $sinkPath,
			'timeout' => $this->getDownloadTimeout(),
			'headers' => ['User-Agent' => 'Nextcloud-Versioniq'],
			// SSRF defence-in-depth: block fetches to internal addresses even
			// though $url originates from a trusted-source GitHub release JSON.
			// Mirrors PatValidator. See OWASP A10:2021.
			'nextcloud' => ['allow_local_address' => $this->configs->config->getSystemValueBool('allow_local_remote_servers', false)],
		];

		if ($pat === null) {
			$this->download->clientService->newClient()->get($url, $options);

			return;
		}

		$this->pats->patManager->useToken($pat, function (string $token) use ($url, $options): void {
			$options['headers']['Authorization'] = 'Bearer ' . $token;
			$this->download->clientService->newClient()->get($url, $options);
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

		return self::checksumOutcome($this->fetchChecksumFile($shaUrl, $pat), $actualSha);
	}

	/**
	 * Fetches the `.sha256` asset, with the PAT when there is one; null (and
	 * a warning in the log) when the request fails.
	 */
	private function fetchChecksumFile(string $shaUrl, ?\OCA\Versioniq\Db\Pat $pat): ?IResponse {
		$options = [
			'timeout' => 30,
			'headers' => ['User-Agent' => 'Nextcloud-Versioniq'],
			// SSRF defence-in-depth: same rationale as authenticatedDownload.
			'nextcloud' => ['allow_local_address' => $this->configs->config->getSystemValueBool('allow_local_remote_servers', false)],
		];

		try {
			if ($pat === null) {
				return $this->download->clientService->newClient()->get($shaUrl, $options);
			}

			return $this->pats->patManager->useToken($pat, function (string $token) use ($shaUrl, $options) {
				$options['headers']['Authorization'] = 'Bearer ' . $token;

				return $this->download->clientService->newClient()->get($shaUrl, $options);
			});
		} catch (Exception $error) {
			$this->logger->warning('External installer: could not fetch .sha256', [
				'shaUrl' => $shaUrl,
				'message' => $error->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * The integrity warning for a fetched `.sha256` answer, or null when it
	 * matches the archive.
	 *
	 * @throws Exception when the advertised digest differs from the archive's
	 */
	private static function checksumOutcome(?IResponse $response, string $actualSha): ?string {
		if (!self::answeredOk($response)) {
			return 'Failed to fetch advertised SHA-256; install proceeded without verification.';
		}

		$expected = self::advertisedSha((string)$response->getBody());
		if ($expected === null) {
			return 'SHA-256 file format unrecognized; install proceeded without verification.';
		}

		self::assertDigestMatches($expected, $actualSha);

		return null;
	}

	/**
	 * @throws Exception when the advertised digest differs from the archive's
	 */
	private static function assertDigestMatches(string $expected, string $actualSha): void {
		if (!hash_equals($expected, $actualSha)) {
			throw new Exception(sprintf('SHA-256 mismatch — expected %s, got %s.', $expected, $actualSha));
		}
	}

	/**
	 * @psalm-assert-if-true IResponse $response
	 */
	private static function answeredOk(?IResponse $response): bool {
		return $response !== null && $response->getStatusCode() === 200;
	}

	/**
	 * The lower-cased digest a `.sha256` body advertises, as a raw hash or as
	 * `<hash>  <filename>`, or null when it is not 64 hex characters.
	 */
	private static function advertisedSha(string $body): ?string {
		$expected = preg_split('/\s+/', trim($body))[0] ?? '';

		return preg_match('/^[a-f0-9]{64}$/i', $expected) === 1 ? strtolower($expected) : null;
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

		$l = Server::get(IFactory::class)->get('core');
		$info = $this->appManager->getAppInfoByPath($infoXml, $l->getLanguageCode());
		if (!is_array($info) || ($info['id'] ?? null) !== $expectedAppId) {
			throw new Exception('appinfo/info.xml could not be loaded by app manager.');
		}
		/** @var array<string, mixed> $info */

		$ignoreMaxApps = (array)$this->configs->config->getSystemValue('app_install_overwrite', []);
		$ignoreMax = in_array($expectedAppId, $ignoreMaxApps, true);
		$serverVersion = Server::get(\OCP\ServerVersion::class)->getVersionString();
		// Public IAppManager::isAppCompatible() (since 32, this app's floor)
		// instead of the deprecated \OC_App static. The static used to be
		// needed because IAppManager lacked the method on Nextcloud 31.
		if (!$this->appManager->isAppCompatible($serverVersion, $info, $ignoreMax)) {
			$appName = isset($info['name']) && is_string($info['name']) ? $info['name'] : $expectedAppId;
			throw new Exception(sprintf(
				'App "%s" is not compatible with this Nextcloud version.',
				$appName
			));
		}

		\OC_App::checkAppDependencies($this->configs->config, $l, $info, $ignoreMax);

		return $info;
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
