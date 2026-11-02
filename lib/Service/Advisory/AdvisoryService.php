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

use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceLookup;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Correlates the currently-installed/pinned version of each app against the
 * published security advisories resolved from the app's bound source (the
 * App Store security feed for store-sourced apps; the forge advisory endpoint
 * for external-sourced apps).
 *
 * The correlation is read-only: it never installs, unpins, or otherwise mutates
 * an app's version — Versioniq surfaces the advisory and the recommended
 * safe version, and the administrator decides. External fetches are delegated
 * to the source drivers (which reuse the existing PAT/credential path); this
 * service adds no HTTP client of its own.
 *
 * States (per app):
 *   - `pinned-to-vulnerable`: the installed/pinned version is itself within an
 *     advisory's affected range. This is the failure mode this tool can
 *     uniquely create (deliberate pinning to an older version) and is therefore
 *     the prominent, must-own state.
 *   - `advisory-available`: the installed version is NOT affected, but the
 *     source reports advisories for the app affecting other (older) versions —
 *     i.e. the app has a security history and the installed version is at or
 *     above the fix. Informational.
 *   - `none`: the source reports no advisories for the app.
 *
 * @psalm-api
 */
class AdvisoryService {
	public const STATE_NONE = 'none';

	/**
	 * Default wall-clock ceiling for a correlateAll() sweep, in seconds.
	 *
	 * Sized for a caller someone is waiting on: App.vue aborts its background
	 * fetches at 8s, so a budget above that would be spent producing a
	 * response nobody is still waiting for. A bound must fit inside the bound
	 * that contains it.
	 *
	 * NOTE that this default is now a fallback, not the normal path. The
	 * request path no longer sweeps at all — it reads the snapshot written by
	 * AdvisoryRefreshJob — and the job passes its own, far larger budget.
	 * Leaving 5s as the default here would have quietly clipped the JOB to a
	 * handful of apps, which is the opposite of what a background sweep is
	 * for: the budget that made the endpoint answerable would have become the
	 * budget that made the coverage wrong.
	 *
	 * @var float
	 */
	public const CORRELATE_ALL_BUDGET_SECONDS = 5.0;
	public const STATE_AVAILABLE = 'advisory-available';
	public const STATE_VULNERABLE = 'pinned-to-vulnerable';

	/**
	 * Key used for the Nextcloud server's own advisory row.
	 *
	 * The server is not an app, but 95 of the 277 published advisories are
	 * about it — by far the largest single subject in the feed — and an
	 * administrator running a vulnerable server wants to know. It is keyed
	 * distinctly so no caller mistakes it for an installed app.
	 */
	public const SERVER_KEY = AdvisoryPackageMap::SERVER;

	public function __construct(
		private SourceLookup $sources,
		private IAppManager $appManager,
		private AdvisoryMatching $matching,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Correlates a single app: resolves its binding + source, fetches advisories
	 * and available versions, and evaluates the state against the installed
	 * version. Returns a `none` result (no error) for apps whose source cannot
	 * answer advisories, so callers can treat the map uniformly.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions?: list<string>}> $feedAdvisories
	 *                                                                                                                                                                         Advisories the central feed already resolved to this app. Passed in
	 *                                                                                                                                                                         rather than fetched here because the feed is read ONCE per sweep.
	 * @return array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}
	 */
	public function correlate(string $appId, array $feedAdvisories = []): array {
		$installedVersion = $this->installedVersion($appId);

		$binding = $this->sources->bindingStore->get($appId) ?? SourceBinding::appStore();
		$source = $this->sources->sourceRegistry->get($binding);

		if ($installedVersion === null) {
			return $this->emptyResult($appId, null);
		}

		// An app whose source cannot answer advisories is NOT a dead end any
		// more: the centrally-published feed may still cover it, and for App
		// Store apps it is the only thing that does.
		if (!$source instanceof AdvisorySourceInterface) {
			if ($feedAdvisories === []) {
				return $this->emptyResult($appId, $installedVersion);
			}

			$evaluated = $this->evaluate($installedVersion, $feedAdvisories, []);
			$evaluated['appId'] = $appId;
			$evaluated['error'] = null;

			return $evaluated;
		}

		$advisoryResult = $source->listAdvisories($appId, $binding);
		$advisories = [...$feedAdvisories, ...$advisoryResult['advisories']];
		$error = $advisoryResult['error'];

		$available = [];
		$versionResult = $source->listVersions($appId, $binding);
		foreach ($versionResult['versions'] as $entry) {
			$available[] = $entry['version'];
		}

		$evaluated = $this->evaluate($installedVersion, $advisories, $available);
		$evaluated['appId'] = $appId;
		$evaluated['error'] = $error;

		return $evaluated;
	}

	/**
	 * Correlates every enabled app, keyed by app id. Individual app failures are
	 * logged and surfaced as an `error` on that app's entry, never thrown, so a
	 * single unreachable source does not abort the whole sweep.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param ?float $budgetSeconds Wall-clock ceiling for the sweep. Callers a
	 *                              user is waiting on should leave this null (see the default). The
	 *                              background refresh passes its own, much larger budget so that moving
	 *                              the work off the request path does not silently shrink what the
	 *                              feature covers.
	 * @return array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}>
	 */
	public function correlateAll(?float $budgetSeconds = null): array {
		$results = [];
		$deadline = microtime(true) + ($budgetSeconds ?? self::CORRELATE_ALL_BUDGET_SECONDS);

		// ONE fetch for the whole sweep. The advisories that actually exist are
		// published centrally, not per app, so asking each app's own source was
		// asking 87 of 88 apps a question their source cannot answer (#166).
		$feed = $this->matching->advisoryFeed->fetchAll();
		$feedAdvisories = $feed['advisories'];
		if ($feed['error'] !== null) {
			$this->logger->warning('AdvisoryService: the Nextcloud advisory feed could not be read in full', [
				'message' => $feed['error'],
			]);
		}

		foreach ($this->appManager->getEnabledApps() as $appId) {
			// BUDGET, BECAUSE THIS ENDPOINT COULD NOT PREVIOUSLY RETURN AT ALL.
			//
			// correlate() makes TWO source calls per app (listAdvisories and
			// listVersions), so an instance with 88 enabled apps issues 176
			// sequential external calls on a page-load path. Measured on a live
			// instance: /api/advisories did not answer within 120s, twice.
			//
			// It could not even warm its own per-app payload cache, because the
			// cache is written on completion and the request never completed —
			// so a second call was exactly as slow as the first.
			//
			// The knock-on was worse than a slow badge: this is dispatched
			// first of the three background loaders, and while it held the PHP
			// session lock /api/pins never ran, so pin badges never rendered
			// and nothing anywhere reported why (issue #160).
			//
			// Apps not reached report `error` rather than being dropped: the
			// caller already treats that field as "could not answer", so
			// coverage is unchanged in shape — what changes is that a slow
			// source degrades to a stated gap instead of an endpoint that hangs.
			if (microtime(true) >= $deadline) {
				$results[$appId] = $this->emptyResult(
					$appId,
					$this->installedVersion($appId),
					'Advisory correlation budget exceeded before this app was reached; its sources were not queried.'
				);
				continue;
			}

			try {
				$results[$appId] = $this->correlate($appId, $feedAdvisories[$appId] ?? []);
			} catch (\Throwable $error) {
				$this->logger->warning('AdvisoryService: correlation failed for app', [
					'app' => $appId,
					'message' => $error->getMessage(),
				]);
				$results[$appId] = $this->emptyResult($appId, $this->installedVersion($appId), $error->getMessage());
			}
		}

		// The server's own row. It is not an app, but 95 of the 277 published
		// advisories are about it — the largest single subject in the feed —
		// and an administrator on a vulnerable server has to be told.
		$serverAdvisories = $feedAdvisories[self::SERVER_KEY] ?? [];
		if ($serverAdvisories !== []) {
			$installed = $this->matching->serverVersion->current();
			$evaluated = $this->evaluate($installed, $serverAdvisories, []);
			$evaluated['appId'] = self::SERVER_KEY;
			$evaluated['error'] = null;
			$results[self::SERVER_KEY] = $evaluated;
		}

		return $results;
	}

	/**
	 * Pure correlation: given the installed version, the advisories from the
	 * source, and the versions available from the source, computes the advisory
	 * state and the nearest resolving version. No I/O — this is the unit-tested
	 * core of the feature.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions?: list<string>}> $advisories
	 *                                                                                                                                                                     `patchedVersions` is present on records from the central Nextcloud
	 *                                                                                                                                                                     feed and absent on the older per-source shape. Its presence is what
	 *                                                                                                                                                                     selects branch-aware evaluation over clause evaluation, so it is part
	 *                                                                                                                                                                     of the contract rather than an optional extra.
	 * @param list<string> $availableVersions
	 * @return array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}
	 */
	public function evaluate(string $installedVersion, array $advisories, array $availableVersions): array {
		if ($advisories === []) {
			return [
				'appId' => '',
				'installedVersion' => $installedVersion,
				'state' => self::STATE_NONE,
				'advisories' => [],
				'recommendedVersion' => null,
				'error' => null,
			];
		}

		// Two evaluation paths, chosen by what the record carries.
		//
		// A record with `patchedVersions` came from the Nextcloud feed, which
		// describes several maintenance branches per advisory. Its comma-
		// separated range CANNOT be read as a boolean: measured over the
		// published corpus, 66.5% of entries are multiple lower bounds with no
		// upper bound, which ANDed collapse to the highest and clear a
		// genuinely vulnerable instance. BranchAwareRange resolves those from
		// the patch list instead.
		//
		// A record without it came from a forge source in the older shape, and
		// keeps the original clause semantics.
		$active = [];
		$branchPatches = [];
		foreach ($advisories as $advisory) {
			$patchedVersions = $advisory['patchedVersions'] ?? [];
			if ($patchedVersions !== []) {
				$patch = $this->matching->branchRange->resolvePatch($installedVersion, $patchedVersions);
				if ($patch !== null) {
					$active[] = $advisory;
					$branchPatches[] = $patch;
				}
				continue;
			}

			if ($this->isAffected($installedVersion, $advisory['affected'])) {
				$active[] = $advisory;
			}
		}

		if ($active === []) {
			// The app has advisories, but none affect the installed version:
			// the installed version is already safe.
			return [
				'appId' => '',
				'installedVersion' => $installedVersion,
				'state' => self::STATE_AVAILABLE,
				'advisories' => $this->summarise($advisories),
				'recommendedVersion' => null,
				'error' => null,
			];
		}

		// The advisory's own patch beats a guess from the version list: it is
		// the version the publisher states resolves the issue on THIS branch,
		// whereas nearestResolving() infers one from whatever the source
		// happens to offer.
		$recommended = $branchPatches !== []
			? $this->lowestVersion($branchPatches)
			: $this->nearestResolving($installedVersion, $active, $availableVersions);

		return [
			'appId' => '',
			'installedVersion' => $installedVersion,
			'state' => self::STATE_VULNERABLE,
			'advisories' => $this->summarise($active),
			'recommendedVersion' => $recommended,
			'error' => null,
		];
	}

	/**
	 * The lowest of several branch patches — when more than one advisory
	 * affects the installed version, the nearest upgrade that starts resolving
	 * them is the one to name.
	 *
	 * @param non-empty-list<string> $versions
	 */
	private function lowestVersion(array $versions): string {
		$lowest = $versions[0];
		foreach ($versions as $candidate) {
			if (version_compare($candidate, $lowest, '<')) {
				$lowest = $candidate;
			}
		}

		return $lowest;
	}

	/**
	 * Reduces advisory records to the id/severity/summary triple surfaced to
	 * the admin (drops the internal affected-range / patch fields).
	 *
	 * @param list<array{id: string, severity: string, summary: string, affected?: list<string>, firstPatchedVersion?: ?string, patchedVersions?: list<string>}> $advisories
	 * @return list<array{id: string, severity: string, summary: string}>
	 */
	private function summarise(array $advisories): array {
		// No array_values(): $advisories is already a list, so mapping it
		// yields a list.
		return array_map(
			static fn (array $a): array => [
				'id' => $a['id'],
				'severity' => $a['severity'],
				'summary' => $a['summary'],
			],
			$advisories,
		);
	}

	/**
	 * A version is affected by an advisory when EVERY affected clause holds
	 * (AND semantics — a range such as ">= 1.0.0, < 1.2.3" is two clauses). An
	 * empty clause list means "all versions affected".
	 *
	 * @param list<string> $affected
	 */
	private function isAffected(string $version, array $affected): bool {
		if ($affected === []) {
			return true;
		}
		foreach ($affected as $clause) {
			if (!$this->satisfiesClause($version, $clause)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluates a single `op version` clause (e.g. `< 1.2.3`, `>= 1.0.0`,
	 * `= 1.0.0`) against a version using PHP's version_compare. A bare version
	 * with no operator is treated as equality. Unparseable clauses are treated
	 * as non-matching (fail-safe: they do not spuriously mark a version
	 * affected).
	 */
	private function satisfiesClause(string $version, string $clause): bool {
		$clause = trim($clause);
		if ($clause === '') {
			return true;
		}

		if (preg_match('/^(<=|>=|<|>|=)?\s*(.+)$/', $clause, $matches) !== 1) {
			return false;
		}
		$bound = trim($matches[2]);
		if ($bound === '') {
			return false;
		}

		// version_compare's third argument is a CLOSED SET, and with an
		// operator outside it the function returns null rather than a bool.
		// The regex above already constrains the input, but naming the set
		// here makes that guarantee visible to the type system instead of
		// leaving a `bool|null` that only happens to be safe.
		$operator = match ($matches[1]) {
			'<' => '<',
			'<=' => '<=',
			'>' => '>',
			'>=' => '>=',
			// A bare version with no operator means equality.
			'=', '' => '=',
			default => null,
		};
		if ($operator === null) {
			return false;
		}

		return version_compare($version, $bound, $operator);
	}

	/**
	 * The nearest version, strictly newer than the installed one, that is not
	 * affected by any of the active advisories — the recommended safe upgrade.
	 * Prefers the published `firstPatchedVersion` when it is offered by the
	 * source; otherwise scans the available versions ascending. Returns null
	 * when the source offers no resolving version (stuck-on-vulnerable).
	 *
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions?: list<string>}> $active
	 * @param list<string> $availableVersions
	 */
	private function nearestResolving(string $installedVersion, array $active, array $availableVersions): ?string {
		$candidates = [];
		foreach ($availableVersions as $candidate) {
			if (version_compare($candidate, $installedVersion, '>')) {
				$candidates[] = $candidate;
			}
		}
		// Fold in any published first-patched versions the source named, even
		// if they were not in the available-versions list.
		foreach ($active as $advisory) {
			$patched = $advisory['firstPatchedVersion'];
			if (is_string($patched) && $patched !== '' && version_compare($patched, $installedVersion, '>')) {
				$candidates[] = $patched;
			}
		}
		$candidates = array_values(array_unique($candidates));
		usort($candidates, static fn (string $a, string $b): int => version_compare($a, $b));

		foreach ($candidates as $candidate) {
			$stillVulnerable = false;
			foreach ($active as $advisory) {
				if ($this->isAffected($candidate, $advisory['affected'])) {
					$stillVulnerable = true;
					break;
				}
			}
			if (!$stillVulnerable) {
				return $candidate;
			}
		}

		return null;
	}

	private function installedVersion(string $appId): ?string {
		try {
			$installed = $this->appManager->getAppVersion($appId);
		} catch (\Throwable) {
			return null;
		}

		return $installed !== '' ? $installed : null;
	}

	/**
	 * @return array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}
	 */
	private function emptyResult(string $appId, ?string $installedVersion, ?string $error = null): array {
		return [
			'appId' => $appId,
			'installedVersion' => $installedVersion,
			'state' => self::STATE_NONE,
			'advisories' => [],
			'recommendedVersion' => null,
			'error' => $error,
		];
	}
}
