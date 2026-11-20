<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Persists the last bundled-library check per app, beside the advisory
 * snapshot and on the same pattern: JSON in app config, the previous snapshot
 * kept when a new one cannot be encoded, and a null `checkedAt` for "never
 * checked" so the page can tell that apart from "checked, nothing found".
 *
 * Library findings are kept apart from the app advisory snapshot on purpose:
 * no app version is known to fix them, so they must never feed the app's
 * advisory state, the compliance status or an automatic install.
 *
 * @psalm-api
 */
class LibraryAdvisoryStore {
	/** App config key of the snapshot; its time is under `advisory.libraries.checkedAt`. */
	public const KEY = 'advisory.libraries';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores a completed check and the moment it completed.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @param array<string, array{manifest: ?string, checked: int, skipped: int, error: ?string, findings: list<array<string, mixed>>}> $libraries per app id
	 */
	public function save(array $libraries, int $checkedAt): void {
		try {
			$this->snapshot()->write($libraries, $checkedAt);
		} catch (\JsonException $error) {
			$this->logger->error('LibraryAdvisoryStore: could not encode the library snapshot; keeping the previous one', [
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * The stored snapshot, or an empty map with a null `checkedAt` when no
	 * check has completed.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @return array{libraries: array<string, mixed>, checkedAt: ?int}
	 */
	public function read(): array {
		try {
			$libraries = $this->snapshot()->readMap();
		} catch (\JsonException $error) {
			$this->logger->warning('LibraryAdvisoryStore: stored snapshot is not valid JSON; reporting as never checked', [
				'message' => $error->getMessage(),
			]);

			return ['libraries' => [], 'checkedAt' => null];
		}

		if ($libraries === null) {
			return ['libraries' => [], 'checkedAt' => null];
		}

		return ['libraries' => $libraries, 'checkedAt' => $this->snapshot()->checkedAt()];
	}

	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
