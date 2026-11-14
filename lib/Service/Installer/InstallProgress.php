<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The live progress of one install, stage by stage (install-live-progress D1,
 * D2). The installers report each stage as it starts; the record
 * `{stages: [{name, startedAt, endedAt, detail}], outcome}` lives in the
 * distributed cache for ten minutes under the page's token, so the page can
 * poll it while the install request runs.
 *
 * Progress only observes: every write swallows its own failure, so a cache
 * that is down never breaks an install. Without a token nothing is recorded.
 *
 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
 */
class InstallProgress {
	/** The cache namespace. */
	public const CACHE_PREFIX = 'versioniq-progress';

	/** How long a record lives, in seconds. */
	public const TTL = 600;

	/** What a token looks like: the page's random id. */
	private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/D';

	/** The token of the install this request reports, or null. */
	private ?string $token = null;

	/**
	 * The record being written.
	 *
	 * @var array{stages: list<array{name: string, startedAt: int, endedAt: ?int, detail: array<string, mixed>}>, outcome: ?string}
	 */
	private array $record = ['stages' => [], 'outcome' => null];

	/** The cache, once resolved. */
	private ?ICache $cache = null;

	/** @var (callable(string): void)|null Told the name of each new stage, with or without a token (the CLI). */
	private $listener = null;

	private ?string $lastHeard = null;

	public function __construct(
		private ICacheFactory $cacheFactory,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether a string can be a progress token.
	 *
	 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
	 */
	public static function isToken(string $token): bool {
		return preg_match(self::TOKEN_PATTERN, $token) === 1;
	}

	/**
	 * Start reporting under a token; an invalid token reports nothing.
	 *
	 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
	 */
	public function start(string $token): void {
		$this->token = null;
		$this->record = ['stages' => [], 'outcome' => null];
		if (self::isToken($token) === false) {
			return;
		}

		$this->token = $token;
		$this->save();
	}

	/**
	 * The stage that starts now; the running one ends. A repeated name with
	 * new detail (download progress) updates the running stage instead.
	 *
	 * @param array<string, mixed> $detail What the stage reports, such as bytes downloaded.
	 *
	 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
	 */
	public function stage(string $name, array $detail = []): void {
		$this->tell(name: $name);
		if ($this->token === null) {
			return;
		}

		$now = $this->time->getTime();
		$last = array_key_last($this->record['stages']);
		if ($last !== null && $this->record['stages'][$last]['name'] === $name && $this->record['stages'][$last]['endedAt'] === null) {
			$this->record['stages'][$last]['detail'] = $detail;
			$this->save();
			return;
		}

		$this->endRunning(now: $now);
		$this->record['stages'][] = ['name' => $name, 'startedAt' => $now, 'endedAt' => null, 'detail' => $detail];
		$this->save();
	}

	/**
	 * A Guzzle `progress` callback that reports the download as the `downloading`
	 * stage every 5 %, when the response carries a length.
	 *
	 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md
	 * @return callable(mixed, mixed, mixed=, mixed=): void
	 */
	public function downloadProgress(): callable {
		$reported = -1;

		return function (mixed $total, mixed $done) use (&$reported): void {
			$total = (int)$total;
			if ($total <= 0) {
				return;
			}

			$step = intdiv(intdiv(min((int)$done, $total) * 100, $total), 5) * 5;
			if ($step <= $reported) {
				return;
			}

			$reported = $step;
			$this->stage(name: 'downloading', detail: ['percent' => $step]);
		};
	}

	/**
	 * The install ended: the running stage ends and the outcome is recorded.
	 *
	 * @param string $outcome `success`, `failed` or `rolled-back`.
	 *
	 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
	 */
	public function finish(string $outcome): void {
		if ($this->token === null) {
			return;
		}

		$this->endRunning(now: $this->time->getTime());
		$this->record['outcome'] = $outcome;
		$this->save();
		$this->token = null;
	}

	/**
	 * The stored record of a token, or null when it is unknown, expired or
	 * cannot be read.
	 *
	 * @return array{stages: list<array<string, mixed>>, outcome: ?string}|null
	 *
	 * @spec openspec/changes/install-live-progress/specs/version-management/spec.md#requirement-an-admin-sees-each-stage-of-an-install-while-it-runs
	 */
	public function read(string $token): ?array {
		if (self::isToken($token) === false) {
			return null;
		}

		try {
			$stored = $this->cache()->get($token);
		} catch (Throwable $e) {
			$this->logger->debug('Versioniq install progress could not be read: ' . $e->getMessage());
			return null;
		}

		if (is_string($stored) === false) {
			return null;
		}

		$record = json_decode($stored, true);
		if (is_array($record) === false || is_array($record['stages'] ?? null) === false) {
			return null;
		}

		return ['stages' => array_values($record['stages']), 'outcome' => is_string($record['outcome'] ?? null) ? $record['outcome'] : null];
	}

	/**
	 * Be told the name of each stage as it starts, for `occ versioniq:install`;
	 * null stops it. A failing listener never fails the install.
	 *
	 * @spec openspec/changes/install-live-progress/specs/cli-commands/spec.md
	 * @param (callable(string): void)|null $listener
	 */
	public function listen(?callable $listener): void {
		$this->listener = $listener;
		$this->lastHeard = null;
	}

	private function tell(string $name): void {
		if ($this->listener === null || $name === $this->lastHeard) {
			return;
		}

		$this->lastHeard = $name;
		try {
			($this->listener)($name);
		} catch (Throwable $e) {
			$this->logger->debug('Versioniq install progress listener failed: ' . $e->getMessage());
		}
	}

	/**
	 * End the running stage, if any.
	 */
	private function endRunning(int $now): void {
		$last = array_key_last($this->record['stages']);
		if ($last !== null && $this->record['stages'][$last]['endedAt'] === null) {
			$this->record['stages'][$last]['endedAt'] = $now;
		}
	}

	/**
	 * Write the record; a failure is logged and swallowed.
	 */
	private function save(): void {
		try {
			$this->cache()->set((string)$this->token, (string)json_encode($this->record), self::TTL);
		} catch (Throwable $e) {
			$this->logger->debug('Versioniq install progress could not be written: ' . $e->getMessage());
		}
	}

	/**
	 * The distributed cache, or the local one when there is none.
	 */
	private function cache(): ICache {
		if ($this->cache === null) {
			$cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
			if ($this->cacheFactory->isAvailable() === false) {
				$cache = $this->cacheFactory->createLocal(self::CACHE_PREFIX);
			}

			$this->cache = $cache;
		}

		return $this->cache;
	}
}
