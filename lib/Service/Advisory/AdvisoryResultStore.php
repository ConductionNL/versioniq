<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Advisory;

use OCA\Versioniq\Service\Settings\StoredSnapshot;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Persists the most recent full advisory correlation so the admin UI can read
 * a result instead of computing one.
 *
 * WHY THIS EXISTS. Correlating advisories makes two external calls per app
 * (`listAdvisories` + `listVersions`). On an instance with 88 enabled apps
 * that is 176 sequential external calls, which is not work a page-load
 * endpoint can do: measured on a live instance, `GET /api/advisories` did not
 * answer within 120s, twice, and while it held the PHP session lock the
 * sibling `/api/pins` request never ran at all (issue #160).
 *
 * The correlation therefore happens in {@see \OCA\Versioniq\BackgroundJob\AdvisoryRefreshJob},
 * which writes here, and the endpoint reads. That keeps the feature's COVERAGE
 * — every enabled app is still correlated — and pays for it in staleness
 * rather than in a request that cannot return. The stored `checkedAt` is what
 * lets the UI say how old the answer is instead of implying it is live.
 *
 * @psalm-api
 */
class AdvisoryResultStore {
	/**
	 * App config key holding the JSON-encoded correlation snapshot; the unix
	 * time of the last completed sweep is held under `advisory.results.checkedAt`
	 * (see {@see StoredSnapshot}).
	 */
	private const KEY = 'advisory.results';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Stores a completed correlation snapshot and the moment it completed.
	 *
	 * Only ever called after a sweep finishes, so a half-written snapshot
	 * never replaces a good one.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string, cveIds?: list<string>, publishedAt?: ?string, url?: ?string}>, recommendedVersion: ?string, error: ?string, history?: list<array{id: string, severity: string, summary: string, cveIds: list<string>, publishedAt: ?string, url: ?string, affectsInstalled: bool}>, fixStatus?: ?string}> $correlations
	 */
	public function save(array $correlations, int $checkedAt): void {
		try {
			$this->snapshot()->write($correlations, $checkedAt);
		} catch (\JsonException $error) {
			// A snapshot that cannot be encoded must not clear the previous
			// one: a stale answer is worth more than no answer, and the UI
			// states its age either way.
			$this->logger->error('AdvisoryResultStore: could not encode correlation snapshot; keeping the previous one', [
				'message' => $error->getMessage(),
			]);
		}
	}

	/**
	 * Reads the stored snapshot. Returns an empty map with a null `checkedAt`
	 * when no sweep has completed yet — which is a real state on a fresh
	 * install and must be distinguishable from "swept, found nothing".
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array{advisories: array<string, mixed>, checkedAt: ?int}
	 */
	public function read(): array {
		try {
			$advisories = $this->snapshot()->readMap();
		} catch (\JsonException $error) {
			$this->logger->warning('AdvisoryResultStore: stored snapshot is not valid JSON; reporting as never checked', [
				'message' => $error->getMessage(),
			]);

			return ['advisories' => [], 'checkedAt' => null];
		}

		if ($advisories === null) {
			return ['advisories' => [], 'checkedAt' => null];
		}

		return [
			'advisories' => $advisories,
			'checkedAt' => $this->snapshot()->checkedAt(),
		];
	}

	/**
	 * The snapshot under {@see KEY}, its sweep time under `KEY.checkedAt`.
	 */
	private function snapshot(): StoredSnapshot {
		return new StoredSnapshot($this->config, self::KEY);
	}
}
