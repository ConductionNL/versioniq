<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Cache;

use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Persists verified release archives in app data so a rollback can still be
 * served after the upstream source's download URL rots; see "Persist
 * verified artifacts on successful install" and "Cached fallback with full
 * re-verification".
 *
 * Storage layout: one `IAppData` folder per app, named `artifact-cache-
 * {appId}`, each holding `{version}.tar.gz` + `{version}.meta.json` pairs.
 * Folders live at the app data root (not nested under a shared
 * "artifact-cache" folder) because `ISimpleFolder::getDirectoryListing()`
 * only enumerates files, not subfolders — only `IAppData`
 * (`ISimpleRoot`) can list folders, which `summary()`/`clear()` need to
 * enumerate every cached app.
 *
 * Write path (`store()`) is best-effort by construction: a caching failure
 * must never fail an otherwise-successful install (mirrors
 * {@see \OCA\Versioniq\Service\Audit\AuditLogger}). The read path
 * (`fetch()`) re-verifies the stored SHA-256 before returning anything —
 * the tamper gate lives here, not in the caller.
 *
 * @spec openspec/specs/artifact-cache/spec.md
 * @psalm-api
 */
class ArtifactCache {
	private const FOLDER_PREFIX = 'artifact-cache-';
	public const CONFIG_KEEP = 'artifact_cache_keep';
	public const DEFAULT_KEEP = 3;
	private const ARCHIVE_SUFFIX = '.tar.gz';
	private const META_SUFFIX = '.meta.json';

	private const APP_ID_PATTERN = '/^[a-z][a-z0-9_\-]*$/';
	private const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-+_]{0,63}$/';

	private ?IAppData $appData = null;

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Number of archives retained per app; 0 disables caching entirely (no
	 * writes, no fallback reads); see "Persist verified artifacts on
	 * successful install".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 */
	public function getKeep(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_KEEP, self::DEFAULT_KEEP);
	}

	/**
	 * Stores the archive at `$archivePath` plus its verification metadata for
	 * `$appId`/`$version`, then prunes anything beyond `artifact_cache_keep`
	 * (oldest `cachedAt` first). Best-effort: any failure (unwritable app
	 * data, malformed input) is logged and swallowed — the caller's
	 * already-successful install is never affected; see "Cache write failure
	 * is non-fatal".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @param array{sha256?: string, sourceId?: ?string, installerKind?: string, signature?: ?string, certificate?: ?string} $meta
	 */
	public function store(string $appId, string $version, string $archivePath, array $meta): void {
		if ($this->getKeep() <= 0) {
			return;
		}
		if (!$this->isValidAppId($appId) || !$this->isValidVersion($version)) {
			$this->logger->warning('ArtifactCache: refused to store artifact with an invalid appId/version', [
				'appId' => $appId,
				'version' => $version,
			]);

			return;
		}

		try {
			$content = file_get_contents($archivePath);
			if ($content === false) {
				throw new \RuntimeException('Could not read archive at "' . $archivePath . '" for caching.');
			}

			$folder = $this->getOrCreateAppFolder($appId);
			$this->writeFile($folder, $this->archiveFilename($version), $content);

			$fullMeta = [
				'sha256' => isset($meta['sha256']) && is_string($meta['sha256']) ? strtolower($meta['sha256']) : strtolower(hash('sha256', $content)),
				'sourceId' => isset($meta['sourceId']) && is_string($meta['sourceId']) ? $meta['sourceId'] : null,
				'installerKind' => isset($meta['installerKind']) && is_string($meta['installerKind']) ? $meta['installerKind'] : '',
				'signature' => isset($meta['signature']) && is_string($meta['signature']) ? $meta['signature'] : null,
				'certificate' => isset($meta['certificate']) && is_string($meta['certificate']) ? $meta['certificate'] : null,
				'cachedAt' => $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
				'size' => strlen($content),
			];
			$this->writeFile($folder, $this->metaFilename($version), json_encode($fullMeta, JSON_THROW_ON_ERROR));

			$this->prune($folder, $this->getKeep());
		} catch (\Throwable $error) {
			// Best-effort: never let a caching failure propagate into the
			// installer's success path — see "Cache write failure is
			// non-fatal".
			$this->logger->warning('ArtifactCache: failed to store artifact (install unaffected)', [
				'appId' => $appId,
				'version' => $version,
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * Returns the cached archive content + metadata for `$appId`/`$version`,
	 * or `null` when no cache entry exists. Re-verifies the archive's
	 * SHA-256 against the stored metadata before returning anything — a
	 * mismatch discards the entry (both files) and returns `null` — the
	 * tamper gate for "Cached fallback with full re-verification".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return ?array{content: string, meta: array<string, mixed>}
	 */
	public function fetch(string $appId, string $version): ?array {
		if ($this->getKeep() <= 0) {
			return null;
		}
		if (!$this->isValidAppId($appId) || !$this->isValidVersion($version)) {
			return null;
		}

		try {
			$folder = $this->getAppFolder($appId);
			if ($folder === null) {
				return null;
			}

			$metaFile = $folder->getFile($this->metaFilename($version));
			$archiveFile = $folder->getFile($this->archiveFilename($version));
			$meta = json_decode($metaFile->getContent(), true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($meta) || !isset($meta['sha256']) || !is_string($meta['sha256']) || $meta['sha256'] === '') {
				return null;
			}

			$content = $archiveFile->getContent();
			$actualSha = hash('sha256', $content);
			if (!hash_equals(strtolower($meta['sha256']), strtolower($actualSha))) {
				$this->logger->warning('ArtifactCache: cached artifact failed SHA-256 re-verification, discarding', [
					'appId' => $appId,
					'version' => $version,
				]);
				$this->deleteVersion($folder, $version);

				return null;
			}

			/** @var array<string, mixed> $meta */
			return ['content' => $content, 'meta' => $meta];
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable $error) {
			$this->logger->warning('ArtifactCache: failed to read cached artifact, treating as a miss', [
				'appId' => $appId,
				'version' => $version,
				'message' => $error->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Lists the versions currently cached for `$appId` (one directory
	 * listing, no per-version IO), used by
	 * {@see \OCA\Versioniq\Service\InstallerService::getAppVersions()} to
	 * stamp `cachedOffline` on a version listing; see "Cache visibility and
	 * management".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return list<string>
	 */
	public function cachedVersionsFor(string $appId): array {
		if (!$this->isValidAppId($appId)) {
			return [];
		}

		try {
			$folder = $this->getAppFolder($appId);
			if ($folder === null) {
				return [];
			}

			return $this->metaVersionsIn($folder);
		} catch (\Throwable $error) {
			$this->logger->warning('ArtifactCache: failed to list cached versions', [
				'appId' => $appId,
				'message' => $error->getMessage(),
			]);

			return [];
		}
	}

	/**
	 * Cache summary: per-app cached versions + size, and the total size
	 * across all apps; see "Cache visibility and management".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return array{apps: list<array{appId: string, versions: list<string>, sizeBytes: int}>, totalSizeBytes: int, keep: int}
	 */
	public function summary(): array {
		$apps = [];
		$total = 0;

		foreach ($this->listCacheFolders() as $appId => $folder) {
			$versions = $this->metaVersionsIn($folder);
			$size = 0;
			foreach ($folder->getDirectoryListing() as $file) {
				if (str_ends_with($file->getName(), self::ARCHIVE_SUFFIX)) {
					$size += (int)$file->getSize();
				}
			}
			sort($versions);
			$apps[] = ['appId' => $appId, 'versions' => $versions, 'sizeBytes' => $size];
			$total += $size;
		}

		usort($apps, static fn (array $a, array $b): int => $a['appId'] <=> $b['appId']);

		return ['apps' => $apps, 'totalSizeBytes' => $total, 'keep' => $this->getKeep()];
	}

	/**
	 * Removes all cached archives for `$appId`, or the entire cache when
	 * `$appId` is `null`; see "Cache visibility and management" ("Clear
	 * cache").
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 */
	public function clear(?string $appId = null): void {
		if ($appId !== null) {
			if (!$this->isValidAppId($appId)) {
				return;
			}
			try {
				$this->getAppData()->getFolder($this->folderName($appId))->delete();
			} catch (NotFoundException) {
				// Nothing cached for this app — already "cleared".
			} catch (\Throwable $error) {
				$this->logger->warning('ArtifactCache: failed to clear app cache', [
					'appId' => $appId,
					'message' => $error->getMessage(),
				]);
			}

			return;
		}

		foreach ($this->listCacheFolders() as $cachedAppId => $folder) {
			try {
				$folder->delete();
			} catch (\Throwable $error) {
				$this->logger->warning('ArtifactCache: failed to clear app cache', [
					'appId' => $cachedAppId,
					'message' => $error->getMessage(),
				]);
			}
		}
	}

	private function archiveFilename(string $version): string {
		return $version . self::ARCHIVE_SUFFIX;
	}

	private function metaFilename(string $version): string {
		return $version . self::META_SUFFIX;
	}

	private function folderName(string $appId): string {
		return self::FOLDER_PREFIX . $appId;
	}

	private function isValidAppId(string $appId): bool {
		return preg_match(self::APP_ID_PATTERN, $appId) === 1;
	}

	private function isValidVersion(string $version): bool {
		return preg_match(self::VERSION_PATTERN, $version) === 1;
	}

	/**
	 * @return list<string>
	 */
	private function metaVersionsIn(ISimpleFolder $folder): array {
		$versions = [];
		foreach ($folder->getDirectoryListing() as $file) {
			$name = $file->getName();
			if (str_ends_with($name, self::META_SUFFIX)) {
				$versions[] = substr($name, 0, -strlen(self::META_SUFFIX));
			}
		}

		return $versions;
	}

	/**
	 * Every currently-cached app's folder, keyed by appId — the only place
	 * that enumerates app data at the root level, since `ISimpleFolder`
	 * itself cannot list subfolders; see the class docblock.
	 *
	 * @return array<string, ISimpleFolder>
	 */
	private function listCacheFolders(): array {
		$folders = [];
		try {
			foreach ($this->getAppData()->getDirectoryListing() as $folder) {
				$name = $folder->getName();
				if (!str_starts_with($name, self::FOLDER_PREFIX)) {
					continue;
				}
				$appId = substr($name, strlen(self::FOLDER_PREFIX));
				$folders[$appId] = $folder;
			}
		} catch (\Throwable $error) {
			$this->logger->warning('ArtifactCache: could not list app data', ['message' => $error->getMessage()]);
		}

		return $folders;
	}

	private function writeFile(ISimpleFolder $folder, string $name, string $content): void {
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);

			return;
		}
		$folder->newFile($name, $content);
	}

	private function deleteVersion(ISimpleFolder $folder, string $version): void {
		foreach ([$this->archiveFilename($version), $this->metaFilename($version)] as $name) {
			try {
				if ($folder->fileExists($name)) {
					$folder->getFile($name)->delete();
				}
			} catch (\Throwable $error) {
				$this->logger->warning('ArtifactCache: failed to delete cached file', [
					'name' => $name,
					'message' => $error->getMessage(),
				]);
			}
		}
	}

	/**
	 * Prunes the oldest entries (by `cachedAt`) beyond `$keep`; called only
	 * from {@see store()} after a successful write — see "Retention prunes
	 * oldest".
	 */
	private function prune(ISimpleFolder $folder, int $keep): void {
		$entries = [];
		foreach ($folder->getDirectoryListing() as $file) {
			$name = $file->getName();
			if (!str_ends_with($name, self::META_SUFFIX)) {
				continue;
			}
			$version = substr($name, 0, -strlen(self::META_SUFFIX));
			try {
				$meta = json_decode($file->getContent(), true, 16, JSON_THROW_ON_ERROR);
			} catch (\Throwable) {
				continue;
			}
			$cachedAt = is_array($meta) && isset($meta['cachedAt']) && is_string($meta['cachedAt']) ? $meta['cachedAt'] : '';
			$entries[] = ['version' => $version, 'cachedAt' => $cachedAt];
		}

		usort($entries, static fn (array $a, array $b): int => $a['cachedAt'] <=> $b['cachedAt']);

		$excess = count($entries) - $keep;
		for ($i = 0; $i < $excess; $i++) {
			$this->deleteVersion($folder, $entries[$i]['version']);
		}
	}

	private function getAppFolder(string $appId): ?ISimpleFolder {
		try {
			return $this->getAppData()->getFolder($this->folderName($appId));
		} catch (NotFoundException) {
			return null;
		}
	}

	private function getOrCreateAppFolder(string $appId): ISimpleFolder {
		try {
			return $this->getAppData()->getFolder($this->folderName($appId));
		} catch (NotFoundException) {
			return $this->getAppData()->newFolder($this->folderName($appId));
		}
	}

	private function getAppData(): IAppData {
		if ($this->appData === null) {
			$this->appData = $this->appDataFactory->get(Application::APP_ID);
		}

		return $this->appData;
	}
}
