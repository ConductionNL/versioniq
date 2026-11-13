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
		$deadline = microtime(true) + $budgetSeconds;
		$distinct = self::distinct($libraries);
		$client = $this->clientService->newClient();

		$ids = $this->queryAll($client, array_values($distinct));
		if (is_string($ids)) {
			return ['findings' => [], 'unchecked' => array_keys($distinct), 'asked' => count($distinct), 'error' => $ids];
		}

		return $this->resolve($client, $distinct, $ids, ['deadline' => $deadline, 'maxCalls' => $maxDetailCalls]);
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
	 * Fetches the details of every advisory id within the limits and turns
	 * them into findings per library.
	 *
	 * @param array<string, array{name: string, version: string}> $distinct
	 * @param array<string, list<string>> $ids advisory ids per library key
	 * @param array{deadline: float, maxCalls: int} $limits
	 * @return array{findings: array<string, list<array{package: string, version: string, id: string, cveIds: list<string>, severity: string, summary: string, fixedIn: ?string, url: string, malicious: bool}>>, unchecked: list<string>, asked: int, error: ?string}
	 */
	private function resolve(IClient $client, array $distinct, array $ids, array $limits): array {
		$records = [];
		$findings = [];
		$unchecked = [];
		$error = null;
		foreach ($ids as $key => $libraryIds) {
			$library = $distinct[$key];
			foreach ($libraryIds as $id) {
				if (!array_key_exists($id, $records)) {
					if (count($records) >= $limits['maxCalls'] || microtime(true) >= $limits['deadline']) {
						$unchecked[] = $key;
						$error ??= 'The check stopped at its limit; some libraries were not checked this run.';
						continue 2;
					}
					$records[$id] = $this->fetchRecord($client, $id);
				}
				$record = $records[$id];
				if (is_string($record)) {
					$unchecked[] = $key;
					$error ??= $record;
					continue 2;
				}
				$findings[$key][] = self::finding($library, $id, $record);
			}
		}

		return ['findings' => $findings, 'unchecked' => $unchecked, 'asked' => count($distinct), 'error' => $error];
	}

	/**
	 * The advisory ids per library key, following `next_page_token`, or why
	 * the batch query failed.
	 *
	 * @param list<array{name: string, version: string}> $libraries
	 * @return array<string, list<string>>|string
	 */
	private function queryAll(IClient $client, array $libraries): array|string {
		$ids = [];
		foreach (array_chunk($libraries, self::BATCH_SIZE) as $chunk) {
			$pending = array_map(static fn (array $library): array => ['library' => $library, 'token' => null], $chunk);
			for ($round = 0; $pending !== [] && $round < self::MAX_PAGE_ROUNDS; $round++) {
				$results = $this->queryBatch($client, $pending);
				if (is_string($results)) {
					return $results;
				}
				$next = [];
				foreach ($pending as $index => $query) {
					$result = self::arrayAt($results, $index);
					$key = self::key($query['library']);
					$ids[$key] = array_values(array_unique([...($ids[$key] ?? []), ...self::vulnIds($result)]));
					$token = self::text($result, 'next_page_token');
					if ($token !== null && $token !== '') {
						$next[] = ['library' => $query['library'], 'token' => $token];
					}
				}
				$pending = $next;
			}
		}

		return $ids;
	}

	/**
	 * One querybatch request; the `results` list, in query order, or why it failed.
	 *
	 * @param list<array{library: array{name: string, version: string}, token: ?string}> $queries
	 * @return array<array-key, mixed>|string
	 */
	private function queryBatch(IClient $client, array $queries): array|string {
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

		$decoded = $this->request($client, 'post', $this->apiBase() . '/v1/querybatch', [
			'body' => json_encode($body, JSON_THROW_ON_ERROR),
			'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
		]);
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
	private function fetchRecord(IClient $client, string $id): array|string {
		return $this->request($client, 'get', $this->apiBase() . '/v1/vulns/' . rawurlencode($id), [
			'headers' => ['Accept' => 'application/json'],
		]);
	}

	/**
	 * Sends one request and decodes its JSON object, or says why it failed.
	 *
	 * @param 'get'|'post' $method
	 * @param array<string, mixed> $options
	 * @return array<array-key, mixed>|string
	 */
	private function request(IClient $client, string $method, string $url, array $options): array|string {
		$options['timeout'] = self::TIMEOUT_SECONDS;
		// The same SSRF guard the forge clients use: a local address only
		// when the instance allows local remote servers.
		$options['nextcloud'] = ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)];

		try {
			$response = $method === 'post' ? $client->post($url, $options) : $client->get($url, $options);
		} catch (Throwable $error) {
			return 'Could not reach OSV: ' . $error->getMessage();
		}
		if ($response->getStatusCode() !== 200) {
			return 'OSV returned HTTP ' . $response->getStatusCode() . '.';
		}

		try {
			/** @var mixed $decoded */
			$decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return 'OSV returned invalid JSON.';
		}

		return is_array($decoded) ? $decoded : 'OSV returned an unexpected answer.';
	}

	/**
	 * One finding for a library from its OSV record.
	 *
	 * @param array{name: string, version: string} $library
	 * @param array<array-key, mixed> $record
	 * @return array{package: string, version: string, id: string, cveIds: list<string>, severity: string, summary: string, fixedIn: ?string, url: string, malicious: bool}
	 */
	private static function finding(array $library, string $id, array $record): array {
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
		$fixed = [];
		foreach (self::arrays(self::arrayAt($record, 'affected')) as $affected) {
			if (!self::isPackage(self::arrayAt($affected, 'package'), $library['name'])) {
				continue;
			}
			foreach (self::arrays(self::arrayAt($affected, 'ranges')) as $range) {
				foreach (self::arrays(self::arrayAt($range, 'events')) as $event) {
					$version = self::text($event, 'fixed');
					if ($version !== null) {
						$fixed[] = $version;
					}
				}
			}
		}
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
