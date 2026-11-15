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

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use Throwable;

/**
 * Asks the OSV vulnerability database about PHP libraries (ecosystem
 * `Packagist`): one batch query for every distinct library and version, then
 * one detail call per distinct advisory id.
 *
 * Only library names and versions are sent; nothing identifies the instance.
 * Errors are returned, never thrown, so a run that cannot reach OSV degrades
 * to "not checked" instead of aborting.
 *
 * @psalm-api
 */
class OsvClient {
	public const DEFAULT_API_BASE = 'https://api.osv.dev';
	public const ECOSYSTEM = 'Packagist';

	/** OSV's own ceiling for one querybatch request. */
	public const BATCH_SIZE = 1000;

	/** Ids of records that report a malicious package rather than a vulnerability. */
	public const MALICIOUS_PREFIX = 'MAL-';

	private const TIMEOUT_SECONDS = 30;

	/** Follow-up rounds for paged batch results; a cursor that keeps moving past this is cut off. */
	private const MAX_PAGE_ROUNDS = 10;

	/** The HTTP client of the check in progress, made fresh by every check(). */
	private ?IClient $client = null;

	/** The limits of the check in progress: its wall-clock deadline and its cap on detail calls. */
	/** @var array{deadline: float, maxCalls: int} */
	private array $limits = ['deadline' => 0.0, 'maxCalls' => 0];

	/** What the resolve step of the check in progress has gathered so far. */
	/** @var array{distinct: array<string, array{name: string, version: string}>, records: array<string, array<array-key, mixed>|string>, findings: array<string, list<array<string, mixed>>>, unchecked: list<string>, error: ?string} */
	private array $run = ['distinct' => [], 'records' => [], 'findings' => [], 'unchecked' => [], 'error' => null];

	/** The advisory ids per library key the batch query of the check in progress found so far. */
	/** @var array<string, list<string>> */
	private array $found = [];

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * The advisories for each library, keyed `name@version`.
	 *
	 * `unchecked` lists the keys a limit left out of this run (the time budget
	 * or the detail-call cap), so the caller can say so instead of reading
	 * them as clean. `error` is why the run stopped early, or null.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 * @param list<array{name: string, version: string}> $libraries
	 * @return array{findings: array<string, list<array{package: string, version: string, id: string, cveIds: list<string>, severity: string, summary: string, fixedIn: ?string, url: string, malicious: bool}>>, unchecked: list<string>, asked: int, error: ?string}
	 */
	public function check(array $libraries, float $budgetSeconds = 120.0, int $maxDetailCalls = 500): array {
		$this->limits = ['deadline' => microtime(true) + $budgetSeconds, 'maxCalls' => $maxDetailCalls];
		$distinct = self::distinct($libraries);
		$this->client = $this->clientService->newClient();

		$ids = $this->queryAll(array_values($distinct));
		if (is_string($ids)) {
			return ['findings' => [], 'unchecked' => array_keys($distinct), 'asked' => count($distinct), 'error' => $ids];
		}

		return $this->resolve($distinct, $ids);
	}

	/**
	 * The API base: the stored https override, else the public OSV API.
	 *
	 * @spec openspec/changes/advisories-bundled-libraries
	 */
	public function apiBase(): string {
		$stored = rtrim(trim($this->appConfig->getValueString(Application::APP_ID, InstanceSettings::KEY_OSV_API_BASE, '')), '/');

		return str_starts_with($stored, 'https://') ? $stored : self::DEFAULT_API_BASE;
	}

	/**
	 * Fetches the details of every advisory id within the check's limits and
	 * turns them into findings per library.
	 *
	 * @param array<string, array{name: string, version: string}> $distinct
	 * @param array<string, list<string>> $ids advisory ids per library key
	 * @return array{findings: array<string, list<array{package: string, version: string, id: string, cveIds: list<string>, severity: string, summary: string, fixedIn: ?string, url: string, malicious: bool}>>, unchecked: list<string>, asked: int, error: ?string}
	 */
	private function resolve(array $distinct, array $ids): array {
		$this->run = ['distinct' => $distinct, 'records' => [], 'findings' => [], 'unchecked' => [], 'error' => null];
		foreach ($ids as $key => $libraryIds) {
			$this->resolveLibrary($key, $libraryIds);
		}

		return ['findings' => $this->run['findings'], 'unchecked' => $this->run['unchecked'], 'asked' => count($distinct), 'error' => $this->run['error']];
	}

	/**
	 * The findings of one library; at its first record that cannot be read
	 * (a limit, or the fetch failing) the library is left unchecked instead.
	 *
	 * @param list<string> $libraryIds its advisory ids
	 */
	private function resolveLibrary(string $key, array $libraryIds): void {
		$library = $this->run['distinct'][$key];
		foreach ($libraryIds as $id) {
			$record = $this->recordFor($id);
			if (is_string($record)) {
				$this->run['unchecked'][] = $key;
				$this->run['error'] ??= $record;
				return;
			}
			$this->run['findings'][$key][] = self::finding($library, ['id' => $id] + $record);
		}
	}

	/**
	 * One advisory record, fetched once per check, or why it is not there:
	 * the check's limit, or the fetch failing.
	 *
	 * @return array<array-key, mixed>|string
	 */
	private function recordFor(string $id): array|string {
		if (!array_key_exists($id, $this->run['records'])) {
			if (count($this->run['records']) >= $this->limits['maxCalls'] || microtime(true) >= $this->limits['deadline']) {
				return 'The check stopped at its limit; some libraries were not checked this run.';
			}
			$this->run['records'][$id] = $this->fetchRecord($id);
		}

		return $this->run['records'][$id];
	}

	/**
	 * The advisory ids per library key, following `next_page_token`, or why
	 * the batch query failed.
	 *
	 * @param list<array{name: string, version: string}> $libraries
	 * @return array<string, list<string>>|string
	 */
	private function queryAll(array $libraries): array|string {
		$this->found = [];
		foreach (array_chunk($libraries, self::BATCH_SIZE) as $chunk) {
			$error = $this->queryChunk($chunk);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->found;
	}

	/**
	 * Adds the ids of one chunk of libraries to those found, following
	 * `next_page_token` for at most MAX_PAGE_ROUNDS rounds; why it failed, or null.
	 *
	 * @param list<array{name: string, version: string}> $chunk
	 */
	private function queryChunk(array $chunk): ?string {
		$pending = array_map(static fn (array $library): array => ['library' => $library, 'token' => null], $chunk);
		for ($round = 0; $pending !== [] && $round < self::MAX_PAGE_ROUNDS; $round++) {
			$results = $this->queryBatch($pending);
			if (is_string($results)) {
				return $results;
			}
			$pending = $this->collectRound($pending, $results);
		}

		return null;
	}

	/**
	 * Adds one round's ids per library and returns the queries with a next page.
	 *
	 * @param list<array{library: array{name: string, version: string}, token: ?string}> $pending
	 * @param array<array-key, mixed> $results
	 * @return list<array{library: array{name: string, version: string}, token: ?string}>
	 */
	private function collectRound(array $pending, array $results): array {
		$next = [];
		foreach ($pending as $index => $query) {
			$result = self::arrayAt($results, $index);
			$key = self::key($query['library']);
			$this->found[$key] = array_values(array_unique([...($this->found[$key] ?? []), ...self::vulnIds($result)]));
			$token = self::text($result, 'next_page_token');
			if ($token !== null && $token !== '') {
				$next[] = ['library' => $query['library'], 'token' => $token];
			}
		}

		return $next;
	}

	/**
	 * One querybatch request; the `results` list, in query order, or why it failed.
	 *
	 * @param list<array{library: array{name: string, version: string}, token: ?string}> $queries
	 * @return array<array-key, mixed>|string
	 */
	private function queryBatch(array $queries): array|string {
		$body = ['queries' => array_map(static function (array $query): array {
			$entry = [
				'package' => ['name' => $query['library']['name'], 'ecosystem' => self::ECOSYSTEM],
				'version' => $query['library']['version'],
			];
			if ($query['token'] !== null) {
				$entry['page_token'] = $query['token'];
			}

			return $entry;
		}, $queries)];

		$decoded = $this->request($this->apiBase() . '/v1/querybatch', json_encode($body, JSON_THROW_ON_ERROR));
		if (is_string($decoded)) {
			return $decoded;
		}
		/** @var mixed $results */
		$results = $decoded['results'] ?? null;

		return is_array($results) ? array_values($results) : 'OSV returned an unexpected batch answer.';
	}

	/**
	 * One vulnerability record, or why it could not be read.
	 *
	 * @return array<array-key, mixed>|string
	 */
	private function fetchRecord(string $id): array|string {
		return $this->request($this->apiBase() . '/v1/vulns/' . rawurlencode($id));
	}

	/**
	 * Sends one request and decodes its JSON object, or says why it failed.
	 *
	 * @param string $url The address.
	 * @param string|null $jsonBody A JSON body to POST; null sends a GET.
	 * @return array<array-key, mixed>|string
	 */
	private function request(string $url, ?string $jsonBody = null): array|string {
		$options = $this->options($jsonBody);
		try {
			$client = $this->client ?? $this->clientService->newClient();
			$response = $jsonBody !== null ? $client->post($url, $options) : $client->get($url, $options);
		} catch (Throwable $error) {
			return 'Could not reach OSV: ' . $error->getMessage();
		}
		if ($response->getStatusCode() !== 200) {
			return 'OSV returned HTTP ' . $response->getStatusCode() . '.';
		}

		return self::decode((string)$response->getBody());
	}

	/**
	 * The request options: a JSON POST with a body, a GET without one.
	 *
	 * @return array<string, mixed>
	 */
	private function options(?string $jsonBody): array {
		$options = ['headers' => ['Accept' => 'application/json']];
		if ($jsonBody !== null) {
			$options = ['body' => $jsonBody, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']];
		}
		$options['timeout'] = self::TIMEOUT_SECONDS;
		// The same SSRF guard the forge clients use: a local address only
		// when the instance allows local remote servers.
		$options['nextcloud'] = ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)];

		return $options;
	}

	/**
	 * A response body as a decoded JSON object, or why it is not one.
	 *
	 * @return array<array-key, mixed>|string
	 */
	private static function decode(string $body): array|string {
		try {
			/** @var mixed $decoded */
			$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return 'OSV returned invalid JSON.';
		}

		return is_array($decoded) ? $decoded : 'OSV returned an unexpected answer.';
	}

	/**
	 * One finding for a library from its OSV record.
	 *
	 * @param array{name: string, version: string} $library
	 * @param array<array-key, mixed> $record The advisory record, with its `id`.
	 * @return array{package: string, version: string, id: string, cveIds: list<string>, severity: string, summary: string, fixedIn: ?string, url: string, malicious: bool}
	 */
	private static function finding(array $library, array $record): array {
		$id = (string)$record['id'];
		$cveIds = array_values(array_filter(
			self::strings(self::arrayAt($record, 'aliases')),
			static fn (string $alias): bool => str_starts_with($alias, 'CVE-'),
		));

		return [
			'package' => $library['name'],
			'version' => $library['version'],
			'id' => $id,
			'cveIds' => $cveIds,
			// A CVSS vector alone is not turned into a level.
			'severity' => AdvisorySeverity::normalize(self::text(self::arrayAt($record, 'database_specific'), 'severity')),
			'summary' => self::text($record, 'summary') ?? self::text($record, 'details') ?? '',
			'fixedIn' => self::firstFixed($record, $library),
			'url' => 'https://osv.dev/vulnerability/' . rawurlencode($id),
			'malicious' => str_starts_with($id, self::MALICIOUS_PREFIX),
		];
	}

	/**
	 * The first version that fixes the bundled one: the lowest `fixed` event
	 * above it for this package, else the lowest `fixed` event at all.
	 *
	 * @param array<array-key, mixed> $record
	 * @param array{name: string, version: string} $library
	 */
	private static function firstFixed(array $record, array $library): ?string {
		$fixed = self::fixedVersions($record, $library['name']);
		if ($fixed === []) {
			return null;
		}
		usort($fixed, 'version_compare');
		foreach ($fixed as $version) {
			if (version_compare($version, $library['version'], '>')) {
				return $version;
			}
		}

		return $fixed[0];
	}

	/**
	 * Every `fixed` event of the record's ranges for this package, in record order.
	 *
	 * @param array<array-key, mixed> $record
	 * @return list<string>
	 */
	private static function fixedVersions(array $record, string $name): array {
		$fixed = [];
		foreach (self::arrays(self::arrayAt($record, 'affected')) as $affected) {
			if (self::isPackage(self::arrayAt($affected, 'package'), $name)) {
				$fixed = [...$fixed, ...self::rangeFixes($affected)];
			}
		}

		return $fixed;
	}

	/**
	 * The `fixed` events of one affected entry's ranges.
	 *
	 * @param array<array-key, mixed> $affected
	 * @return list<string>
	 */
	private static function rangeFixes(array $affected): array {
		$fixed = [];
		foreach (self::arrays(self::arrayAt($affected, 'ranges')) as $range) {
			foreach (self::arrays(self::arrayAt($range, 'events')) as $event) {
				$version = self::text($event, 'fixed');
				if ($version !== null) {
					$fixed[] = $version;
				}
			}
		}

		return $fixed;
	}

	/**
	 * @param array<array-key, mixed> $package
	 */
	private static function isPackage(array $package, string $name): bool {
		return self::text($package, 'ecosystem') === self::ECOSYSTEM
			&& strtolower(self::text($package, 'name') ?? '') === strtolower($name);
	}

	/**
	 * @param array<array-key, mixed> $result
	 * @return list<string>
	 */
	private static function vulnIds(array $result): array {
		$ids = [];
		foreach (self::arrays(self::arrayAt($result, 'vulns')) as $vuln) {
			$id = self::text($vuln, 'id');
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * The array under a key of decoded JSON, or an empty one.
	 *
	 * @param array<array-key, mixed> $data
	 * @return array<array-key, mixed>
	 */
	private static function arrayAt(array $data, int|string $key): array {
		/** @var mixed $value */
		$value = $data[$key] ?? null;

		return is_array($value) ? $value : [];
	}

	/**
	 * The string under a key of decoded JSON, or null.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private static function text(array $data, string $key): ?string {
		/** @var mixed $value */
		$value = $data[$key] ?? null;

		return is_string($value) ? $value : null;
	}

	/**
	 * The entries of a decoded JSON list that are objects.
	 *
	 * @param array<array-key, mixed> $data
	 * @return list<array<array-key, mixed>>
	 */
	private static function arrays(array $data): array {
		/** @var list<array<array-key, mixed>> */
		return array_values(array_filter($data, is_array(...)));
	}

	/**
	 * The entries of a decoded JSON list that are strings.
	 *
	 * @param array<array-key, mixed> $data
	 * @return list<string>
	 */
	private static function strings(array $data): array {
		/** @var list<string> */
		return array_values(array_filter($data, is_string(...)));
	}

	/**
	 * @param list<array{name: string, version: string}> $libraries
	 * @return array<string, array{name: string, version: string}>
	 */
	private static function distinct(array $libraries): array {
		$distinct = [];
		foreach ($libraries as $library) {
			$distinct[self::key($library)] = $library;
		}

		return $distinct;
	}

	/**
	 * The key a library's findings are stored under.
	 *
	 * @param array{name: string, version: string} $library
	 */
	public static function key(array $library): string {
		return $library['name'] . '@' . $library['version'];
	}
}
