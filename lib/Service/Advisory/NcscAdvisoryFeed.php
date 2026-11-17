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

use DateTimeImmutable;
use Exception;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Connection\ReportedHttp;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads NCSC-NL's CSAF directory incrementally and keeps the documents that
 * name a Nextcloud product (advisories-ncsc-feed D1, D2).
 *
 * A central feed beside {@see NextcloudAdvisoryFeed}, with the same answer
 * shape: records keyed by app id or {@see AdvisoryPackageMap::SERVER}, errors
 * returned rather than thrown. Each sweep reads `changes.csv`, fetches the
 * documents newer than the cursor oldest first (the last 365 days on a first
 * read), at most 300 and within its budget, and moves the cursor only past
 * documents it processed. What it answers comes from the index of every
 * matched document, so an advisory read months ago is still matched against
 * today's installed versions, and a failed read never clears it.
 *
 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
 * @psalm-api
 */
class NcscAdvisoryFeed {
	/** NCSC-NL's CSAF directory; `advisory.ncsc_feed_base` overrides it. */
	public const DEFAULT_BASE = 'https://advisories.ncsc.nl/csaf/v2/';

	public const CONFIG_BASE = 'advisory.ncsc_feed_base';
	public const CONFIG_CURSOR = 'advisory.ncsc.cursor';
	public const CONFIG_INDEX = 'advisory.ncsc.index';
	public const CONFIG_CAUGHT_UP = 'advisory.ncsc.caught_up';

	/** The most documents one sweep fetches. */
	public const MAX_DOCUMENTS = 300;

	/** The time one sweep may spend, in seconds. */
	public const BUDGET_SECONDS = 120.0;

	/** How far back a first read goes, in days. */
	public const FIRST_READ_DAYS = 365;

	private const FETCH_TIMEOUT_SECONDS = 30;

	/**
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	public function __construct(
		private ReportedHttp $http,
		private IAppConfig $config,
		private CsafProductMatcher $matcher,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether an admin switched NCSC-NL checks on; off by default.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	public function isEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, AdvisorySettingsStore::CONFIG_NCSC_ENABLED, false);
	}

	/**
	 * Read what is new in the directory, then answer every indexed advisory.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @return array{advisories: array<string, list<array<string, mixed>>>, error: ?string}
	 */
	public function fetchAll(float $budgetSeconds = self::BUDGET_SECONDS): array {
		$read = new NcscRead(index: $this->storedIndex(), cursor: $this->config->getValueString(Application::APP_ID, self::CONFIG_CURSOR, ''));
		$lines = $this->changes();
		if (is_string($lines)) {
			$read->error = $lines;
			$read->caughtUp = false;
		} else {
			$read->deadline = $this->time->getTime() + $budgetSeconds;
			$this->process(read: $read, pending: $this->pending(lines: $lines, cursor: $read->cursor));
		}

		$this->store(read: $read);

		return ['advisories' => NcscDocument::records($read->index), 'error' => $read->error];
	}

	/**
	 * Fetch and index the pending documents, oldest first, until the list,
	 * the document limit or the budget ends, or a document cannot be fetched.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<array{0: string, 1: string}> $pending Path and time per document.
	 */
	private function process(NcscRead $read, array $pending): void {
		foreach ($pending as [$path, $changed]) {
			if ($read->read >= self::MAX_DOCUMENTS || $this->time->getTime() >= $read->deadline) {
				$read->caughtUp = false;
				return;
			}

			$document = $this->document(path: $path);
			if (is_string($document)) {
				$read->error = $document;
				$read->caughtUp = false;
				return;
			}

			$read->read++;
			$this->indexDocument(read: $read, found: ['document' => $document, 'url' => $this->base() . $path]);
			$read->cursor = $changed;
		}
	}

	/**
	 * Index a document that names a Nextcloud product, replacing an older
	 * version of it; drop any other, and any document that could not be read.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param array{document: ?array<array-key, mixed>, url: string} $found The document and its URL.
	 */
	private function indexDocument(NcscRead $read, array $found): void {
		$targets = $found['document'] === null ? [] : $this->matcher->targets($found['document']);
		$entry = $targets === [] ? null : NcscDocument::entry($found['document'], ['targets' => $targets, 'url' => $found['url']]);
		if ($entry === null) {
			return;
		}

		$read->index[$entry['id']] = $entry;
		$read->matched++;
	}

	/**
	 * The lines newer than the cursor, oldest first; without a cursor, the
	 * lines of the last {@see FIRST_READ_DAYS} days.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @param list<array{0: string, 1: string}> $lines Path and time per line.
	 * @return list<array{0: string, 1: string}>
	 */
	private function pending(array $lines, string $cursor): array {
		$after = self::time($cursor) ?? (new DateTimeImmutable('@' . $this->time->getTime()))->modify('-' . self::FIRST_READ_DAYS . ' days');
		$pending = array_values(array_filter($lines, static fn (array $line): bool => self::time($line[1]) > $after));
		usort($pending, static fn (array $a, array $b): int => self::time($a[1]) <=> self::time($b[1]));

		return $pending;
	}

	/**
	 * The lines of changes.csv as path and time, or why it could not be read.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @return list<array{0: string, 1: string}>|string
	 */
	private function changes(): array|string {
		$response = $this->get(url: $this->base() . 'changes.csv');
		if ($response['error'] !== null) {
			return $response['error'];
		}

		$lines = [];
		foreach (preg_split('/\R/', (string)$response['body']) ?: [] as $raw) {
			$line = self::changeLine(raw: $raw);
			if ($line !== null) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * One changes.csv line as path and time, or null for a line that is not one.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @return array{0: string, 1: string}|null
	 */
	private static function changeLine(string $raw): ?array {
		$fields = str_getcsv(trim($raw), ',', '"', '');
		$path = (string)($fields[0] ?? '');
		$changed = (string)($fields[1] ?? '');
		if (!str_ends_with($path, '.json') || str_contains($path, '..') || self::time($changed) === null) {
			return null;
		}

		return [ltrim($path, '/'), $changed];
	}

	/**
	 * A document, null when it is not a JSON object, or why it could not be fetched.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @return array<array-key, mixed>|string|null
	 */
	private function document(string $path): array|string|null {
		$response = $this->get(url: $this->base() . $path);
		if ($response['error'] !== null) {
			return $response['error'];
		}

		$decoded = json_decode((string)$response['body'], true);

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * The body of a 200 response, or why there is none.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @return array{body: ?string, error: ?string}
	 */
	private function get(string $url): array {
		try {
			$response = $this->http->clientService->newClient()->get($url, ['timeout' => self::FETCH_TIMEOUT_SECONDS]);
		} catch (Throwable $error) {
			return ['body' => null, 'error' => 'Could not read the NCSC-NL advisories: ' . $error->getMessage()];
		}

		return $response->getStatusCode() === 200
			? ['body' => (string)$response->getBody(), 'error' => null]
			: ['body' => null, 'error' => 'The NCSC-NL advisories returned HTTP ' . $response->getStatusCode() . '.'];
	}

	/**
	 * Save the index, the cursor and whether the read caught up, and report it.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	private function store(NcscRead $read): void {
		$this->config->setValueString(Application::APP_ID, self::CONFIG_INDEX, (string)json_encode((object)$read->index, JSON_UNESCAPED_SLASHES), lazy: true);
		$this->config->setValueString(Application::APP_ID, self::CONFIG_CURSOR, $read->cursor);
		$this->config->setValueBool(Application::APP_ID, self::CONFIG_CAUGHT_UP, $read->caughtUp);
		if ($read->error !== null) {
			$this->logger->warning('NcscAdvisoryFeed: ' . $read->error, ['read' => $read->read]);
		}

		$this->http->connectionReports?->ncscFeedRead(['read' => $read->read, 'matched' => $read->matched, 'error' => $read->error]);
	}

	/**
	 * The stored index, or an empty one when it is missing or damaged.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 * @return array<string, array<string, mixed>>
	 */
	private function storedIndex(): array {
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::CONFIG_INDEX, '{}', lazy: true), true);

		return is_array($decoded) ? array_filter($decoded, is_array(...)) : [];
	}

	/**
	 * The directory URL, ending in a slash.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	private function base(): string {
		$override = trim($this->config->getValueString(Application::APP_ID, self::CONFIG_BASE, ''));

		return rtrim($override !== '' ? $override : self::DEFAULT_BASE, '/') . '/';
	}

	/**
	 * A time, or null when it is not one.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/specs/security-advisory-correlation/spec.md#requirement-ncsc-nl-advisories-are-matched-to-the-installed-server-and-apps
	 */
	private static function time(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Exception) {
			return null;
		}
	}
}
