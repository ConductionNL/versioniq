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

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Connection\ReportedHttp;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads Nextcloud's published security advisories and groups them by the thing
 * this instance can act on — an app id, or the server.
 *
 * WHY A CENTRAL FEED RATHER THAN A PER-APP SOURCE. The App Store publishes no
 * advisory data at all: measured 2026-08-21, `garm3.nextcloud.com/api/v1/apps.json`
 * returned 755 entries and 31.7 MB containing no `securityAdvisories` field
 * and nothing advisory-shaped. So the existing per-app correlation asked 87 of
 * 88 apps a question their source could never answer, and recorded the silence
 * as "no advisories" (issue #166).
 *
 * The real data lives in ONE place — the GHSA records published on
 * `nextcloud/security-advisories` — so it is fetched once per sweep and
 * indexed, rather than re-asked per app.
 *
 * @psalm-api
 */
class NextcloudAdvisoryFeed {
	/**
	 * The advisory feed. Overridable via `advisory.feed_base` app config so an
	 * e2e run can point at a fixture, mirroring how `appstore.api_base` works.
	 */
	public const DEFAULT_FEED_URL = 'https://api.github.com/repos/nextcloud/security-advisories/security-advisories';

	/**
	 * Pages to follow before giving up.
	 *
	 * The endpoint IGNORES `?page=` — measured, page 1 and page 2 return byte
	 * -identical bodies — and paginates by an opaque cursor in the `Link`
	 * header instead. A page-number loop would therefore re-read page one until
	 * it hit this cap, so the cursor is not an optimisation: without it the
	 * feed is silently truncated to its first 100 records.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 20;

	private const PER_PAGE = 100;
	private const FETCH_TIMEOUT_SECONDS = 30;

	/**
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function __construct(
		// Its connection report tells integriq how much of the feed a check read.
		private ReportedHttp $http,
		private IAppConfig $config,
		private AdvisoryPackageMap $packageMap,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Every advisory the feed publishes, keyed by app id (or
	 * {@see AdvisoryPackageMap::SERVER}). Packages this instance cannot act on
	 * — uninstalled apps, desktop and mobile clients — are dropped.
	 *
	 * Errors are reported, never thrown: a sweep that cannot reach the feed
	 * must degrade to "could not check", not abort the whole correlation.
	 *
	 * Every outcome is also told to integriq's connection registry
	 * (adopt-connection-registry). The only caller is the advisory sweep, a
	 * scheduled job, so this is never on a page request.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 * @return array{advisories: array<string, list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}>>, error: ?string}
	 */
	public function fetchAll(): array {
		$client = $this->http->clientService->newClient();
		$url = $this->feedUrl() . '?per_page=' . self::PER_PAGE;
		$collected = new FeedCollection();

		for ($pages = 0; $url !== null && $pages < self::MAX_PAGES; $pages++) {
			$page = $this->readPage($client, $url);
			if (is_string($page)) {
				return $this->partial($collected->byTarget, count($collected->seen), $page);
			}
			$fresh = $this->collect($collected, $page[0]);
			// A page that produced nothing new means the cursor is not moving.
			// Stopping is what keeps a server-side pagination change from
			// spinning until MAX_PAGES.
			$url = ($fresh > 0) ? $this->nextCursorUrl($page[1]->getHeader('Link')) : null;
		}

		$this->http->connectionReports?->advisoryFeedRead(count($collected->seen), null);

		return ['advisories' => $collected->byTarget, 'error' => null];
	}

	/**
	 * One page of the feed and its response, or why it could not be read.
	 *
	 * @return array{0: array<array-key, mixed>, 1: IResponse}|string
	 */
	private function readPage(IClient $client, string $url): array|string {
		$response = $this->fetchPage($client, $url);
		if (is_string($response)) {
			return $response;
		}
		$records = $this->decodePage($response);

		return is_string($records) ? $records : [$records, $response];
	}

	/**
	 * The response for one page URL, or why the request failed.
	 */
	private function fetchPage(IClient $client, string $url): IResponse|string {
		try {
			$response = $client->get($url, [
				'timeout' => self::FETCH_TIMEOUT_SECONDS,
				'headers' => ['Accept' => 'application/vnd.github+json'],
			]);
		} catch (Throwable $error) {
			return 'Could not read the Nextcloud advisory feed: ' . $error->getMessage();
		}

		return $response->getStatusCode() === 200
			? $response
			: 'The Nextcloud advisory feed returned HTTP ' . $response->getStatusCode() . '.';
	}

	/**
	 * The records of one page body, or why the body is not a record list.
	 *
	 * @return array<array-key, mixed>|string
	 */
	private function decodePage(IResponse $response): array|string {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			return 'The Nextcloud advisory feed returned invalid JSON: ' . $error->getMessage();
		}

		return is_array($decoded) ? $decoded : 'The Nextcloud advisory feed returned an unexpected payload shape.';
	}

	/**
	 * Adds the page's records not seen before to $collected; returns how
	 * many were new.
	 *
	 * @param array<array-key, mixed> $records
	 */
	private function collect(FeedCollection $collected, array $records): int {
		$fresh = 0;
		/** @var array<array-key, mixed> $record */
		foreach (array_filter($records, is_array(...)) as $record) {
			/** @var mixed $ghsa */
			$ghsa = $record['ghsa_id'] ?? null;
			if (!is_string($ghsa) || $ghsa === '' || isset($collected->seen[$ghsa])) {
				continue;
			}
			$collected->seen[$ghsa] = true;
			$fresh++;
			foreach ($this->targetsFor($record, $ghsa) as $target => $advisory) {
				$collected->byTarget[$target][] = $advisory;
			}
		}

		return $fresh;
	}

	/**
	 * Advisory records for one GHSA entry, keyed by the target it applies to.
	 *
	 * A single advisory routinely names several packages, and may name the
	 * same target twice (Server appears twice in some records); both are
	 * flattened to one entry per target here.
	 *
	 * @param array<array-key, mixed> $record
	 * @return array<string, array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}>
	 */
	private function targetsFor(array $record, string $ghsa): array {
		/** @var mixed $vulnerabilities */
		$vulnerabilities = $record['vulnerabilities'] ?? null;
		if (!is_array($vulnerabilities)) {
			return [];
		}

		$advisory = self::advisoryHeader($record, $ghsa);
		$targets = [];
		/** @var mixed $vulnerability */
		foreach ($vulnerabilities as $vulnerability) {
			$entry = $this->vulnerabilityEntry($vulnerability);
			if ($entry === null) {
				continue;
			}
			$target = $entry['target'];
			$targets[$target] = isset($targets[$target]) ? self::mergeVersions($targets[$target], $entry) : self::newTarget($advisory, $entry);
		}

		return $targets;
	}

	/**
	 * The target and version lists one vulnerability entry names, or null
	 * when it names no package this instance can act on.
	 *
	 * @return array{target: string, affected: list<string>, patched: list<string>}|null
	 */
	private function vulnerabilityEntry(mixed $vulnerability): ?array {
		$package = is_array($vulnerability) ? ($vulnerability['package'] ?? null) : null;
		if (!is_array($package) || !is_string($package['name'] ?? null)) {
			return null;
		}
		$target = $this->packageMap->resolve((string)$package['name']);
		if ($target === null) {
			return null;
		}
		/** @var array<array-key, mixed> $vulnerability */

		return [
			'target' => $target,
			'affected' => $this->splitList(is_string($vulnerability['vulnerable_version_range'] ?? null) ? (string)$vulnerability['vulnerable_version_range'] : ''),
			'patched' => $this->splitList(is_string($vulnerability['patched_versions'] ?? null) ? (string)$vulnerability['patched_versions'] : ''),
		];
	}

	/**
	 * The id, severity and summary every target of one GHSA record shares.
	 *
	 * @param array<array-key, mixed> $record
	 * @return array{id: string, severity: string, summary: string}
	 */
	private static function advisoryHeader(array $record, string $ghsa): array {
		return [
			'id' => $ghsa,
			'severity' => AdvisorySeverity::normalize($record['severity'] ?? null),
			'summary' => is_string($record['summary'] ?? null) ? (string)$record['summary'] : '',
		];
	}

	/**
	 * The advisory record for a target named once so far.
	 *
	 * @param array{id: string, severity: string, summary: string} $advisory
	 * @param array{target: string, affected: list<string>, patched: list<string>} $entry
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}
	 */
	private static function newTarget(array $advisory, array $entry): array {
		return $advisory + [
			'affected' => $entry['affected'],
			// Kept for the existing contract; `patchedVersions` is what
			// BranchAwareRange actually evaluates, because one advisory
			// carries a separate patch per maintenance branch.
			'firstPatchedVersion' => $entry['patched'][0] ?? null,
			'patchedVersions' => $entry['patched'],
		];
	}

	/**
	 * Same advisory, same target, listed twice: the version information is
	 * merged rather than the second entry replacing the first and silently
	 * dropping a branch.
	 *
	 * @param array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>} $known
	 * @param array{target: string, affected: list<string>, patched: list<string>} $entry
	 * @return array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}
	 */
	private static function mergeVersions(array $known, array $entry): array {
		$known['patchedVersions'] = array_values(array_unique([...$known['patchedVersions'], ...$entry['patched']]));
		$known['affected'] = array_values(array_unique([...$known['affected'], ...$entry['affected']]));
		$known['firstPatchedVersion'] = $known['patchedVersions'][0] ?? null;

		return $known;
	}

	/**
	 * Splits a comma-separated version list, dropping empties.
	 *
	 * No interpretation happens here on purpose: whether the commas mean AND
	 * or OR is a question the corpus answers differently per record, and
	 * BranchAwareRange resolves it from the patched versions instead.
	 *
	 * @return list<string>
	 */
	private function splitList(string $raw): array {
		if (trim($raw) === '') {
			return [];
		}

		return array_values(array_filter(
			array_map('trim', explode(',', $raw)),
			static fn (string $part): bool => $part !== '',
		));
	}

	/**
	 * The `rel="next"` URL from a GitHub `Link` header, or null when this is
	 * the last page.
	 */
	private function nextCursorUrl(string $linkHeader): ?string {
		if (trim($linkHeader) === '') {
			return null;
		}

		foreach (explode(',', $linkHeader) as $part) {
			if (!str_contains($part, 'rel="next"')) {
				continue;
			}
			if (preg_match('/<([^>]+)>/', $part, $matches) === 1) {
				return $matches[1];
			}
		}

		return null;
	}

	private function feedUrl(): string {
		$override = trim($this->config->getValueString(Application::APP_ID, 'advisory.feed_base', ''));

		return $override !== '' ? rtrim($override, '/') : self::DEFAULT_FEED_URL;
	}

	/**
	 * Returns whatever was collected before the failure, WITH the error.
	 *
	 * Discarding a partial read would turn a feed that failed on page three
	 * into "no advisories", which is the exact absence-reads-as-reassurance
	 * failure this whole feature keeps hitting.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 * @param array<string, list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}>> $collected
	 * @param int $read How many advisories were read before the failure.
	 * @return array{advisories: array<string, list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string, patchedVersions: list<string>}>>, error: string}
	 */
	private function partial(array $collected, int $read, string $error): array {
		$this->logger->warning('NextcloudAdvisoryFeed: ' . $error, ['collected' => count($collected)]);
		$this->http->connectionReports?->advisoryFeedRead($read, $error);

		return ['advisories' => $collected, 'error' => $error];
	}
}
