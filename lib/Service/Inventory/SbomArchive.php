<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Inventory;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * A copy of the SBOM after every successful real install (inventory-export-sbom-metrics
 * D4), kept in app data folder `sbom` under the id of that install's audit row,
 * newest `sbom.keep` (default 200) only. Best-effort: a failure is logged and never
 * reaches the install.
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
 */
class SbomArchive {
	public const FOLDER = 'sbom';
	public const CONFIG_KEEP = 'sbom.keep';
	public const DEFAULT_KEEP = 200;
	private const SUFFIX = '.cdx.json';

	private ?IAppData $appData = null;

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private SbomExporter $exporter,
		private AuditEntryMapper $auditEntries,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Write the SBOM of the state an install of the app produced, under its newest
	 * successful install row, then prune to the newest `sbom.keep`.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
	 */
	public function writeFor(string $appId): void {
		try {
			$entry = $this->auditEntries->findLatestSuccessfulInstall($appId);
			if ($entry === null) {
				$this->logger->warning('SbomArchive: no install row to keep the SBOM with', ['appId' => $appId]);
				return;
			}

			$folder = $this->folder(create: true);
			$folder->newFile($entry->getId() . self::SUFFIX, $this->exporter->export(SbomWriter::FORMAT_CYCLONEDX));
			$this->prune($folder);
		} catch (\Throwable $error) {
			$this->logger->warning('SbomArchive: the SBOM of an install was not written (install unaffected)', [
				'appId' => $appId,
				'exception' => $error,
			]);
		}
	}

	/**
	 * The SBOM kept for an install row, or null when there is none.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
	 */
	public function read(int $auditEntryId): ?string {
		try {
			return $this->folder(create: false)?->getFile($auditEntryId . self::SUFFIX)->getContent();
		} catch (NotFoundException) {
			return null;
		}
	}

	/**
	 * The install rows that have a kept SBOM, as a set of ids.
	 *
	 * @return array<int, true>
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md#requirement-every-update-leaves-an-sbom-of-the-state-it-produced
	 */
	public function storedIds(): array {
		try {
			$folder = $this->folder(create: false);
		} catch (\Throwable $error) {
			$this->logger->warning('SbomArchive: the kept SBOMs could not be listed', ['exception' => $error]);
			return [];
		}

		$ids = [];
		foreach ($folder?->getDirectoryListing() ?? [] as $file) {
			$id = self::idOf(name: $file->getName());
			if ($id !== null) {
				$ids[$id] = true;
			}
		}

		return $ids;
	}

	public function keep(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, self::CONFIG_KEEP, self::DEFAULT_KEEP));
	}

	/** Delete all but the newest `keep()` copies; the newest is the highest install row id. */
	private function prune(ISimpleFolder $folder): void {
		$files = [];
		foreach ($folder->getDirectoryListing() as $file) {
			$id = self::idOf(name: $file->getName());
			if ($id !== null) {
				$files[$id] = $file;
			}
		}
		krsort($files);
		foreach (array_slice($files, $this->keep(), null, true) as $file) {
			$file->delete();
		}
	}

	private static function idOf(string $name): ?int {
		return preg_match('/^(\d+)' . preg_quote(self::SUFFIX, '/') . '$/', $name, $match) === 1 ? (int)$match[1] : null;
	}

	/** The `sbom` folder; null when it does not exist and `create` is false. */
	private function folder(bool $create): ?ISimpleFolder {
		$this->appData ??= $this->appDataFactory->get(Application::APP_ID);
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			if (!$create) {
				return null;
			}

			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
