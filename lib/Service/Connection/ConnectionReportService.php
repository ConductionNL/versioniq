<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Connection;

use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells integriq's connection registry what Versioniq met on its three outside
 * connections: the App Store, GitHub releases and the Nextcloud advisory feed.
 *
 * Integriq owns the rows the Integrations tab lists and works out each status
 * (hydra change connection-registry, design D4). Versioniq reports what a real
 * request met, and asks for a fresh resolve after a GitHub token save.
 *
 * A version list is a page request, so reporting every outcome would put an
 * event on the request path (ADR-076). The service sends a report when the
 * status changes, or when the last one for that key is an hour old. A refresh
 * clears that record, so the first outcome after a save always goes out.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
 * @psalm-api
 */
class ConnectionReportService {
	/**
	 * Integriq's report event (ADR-041). Named by string so Versioniq stays
	 * installable without integriq: the class exists only when integriq does.
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/** Integriq's refresh event. Named by string for the same reason. */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/** The App Store connection key in lib/Settings/connections.json. */
	public const KEY_APPSTORE = 'appstore';

	/** The GitHub releases connection key in lib/Settings/connections.json. */
	public const KEY_GITHUB = 'github';

	/** The advisory feed connection key in lib/Settings/connections.json. */
	public const KEY_ADVISORIES = 'advisories';

	/**
	 * The forge whose requests are reported. Codeberg is retired, and gets no
	 * row, so its requests are never reported.
	 */
	public const REPORTED_FORGE = 'github';

	/** How long the same status stays unreported. */
	public const THROTTLE_SECONDS = 3600;

	/** The longest failure reason a message carries. */
	public const REASON_LIMIT = 160;

	/** App config key prefix for the last status sent per connection. */
	public const MEMO_PREFIX = 'connection_report.';

	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * After a forge request: report what GitHub answered.
	 *
	 * @param string $forgeId The forge id from ForgeRegistry.
	 * @param string $apiBaseUrl The forge's API base URL, for the host in the message.
	 * @param int|null $httpStatus The HTTP status, or null when no answer came.
	 * @param bool $readable Whether a 200 body could be read.
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function forgeAnswered(string $forgeId, string $apiBaseUrl, ?int $httpStatus, bool $readable = true): bool {
		if ($forgeId !== self::REPORTED_FORGE) {
			return false;
		}

		$outcome = $this->describeForgeAnswer($apiBaseUrl, $httpStatus, $readable);
		if ($outcome === null) {
			return false;
		}

		return $this->reportThrottled(self::KEY_GITHUB, $outcome[0], $outcome[1]);
	}

	/**
	 * What a forge answer says about the GitHub connection.
	 *
	 * A 404 says nothing about the connection: the repository is missing or
	 * private. It returns null, and nothing is reported.
	 *
	 * @param string $apiBaseUrl The forge's API base URL.
	 * @param int|null $httpStatus The HTTP status, or null when no answer came.
	 * @param bool $readable Whether a 200 body could be read.
	 * @return array{0: string, 1: string}|null The status and the message, or null.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function describeForgeAnswer(string $apiBaseUrl, ?int $httpStatus, bool $readable = true): ?array {
		$host = $this->hostOf($apiBaseUrl, 'GitHub');

		if ($httpStatus === null) {
			return ['error', 'Versioniq could not reach ' . $host . '.'];
		}
		if ($httpStatus === 200 && $readable) {
			return ['configured', $host . ' answered the last request.'];
		}
		if ($httpStatus === 200) {
			return ['error', $host . ' answered with a body Versioniq could not read.'];
		}
		if ($httpStatus === 401) {
			return ['limited', $host . ' refused a saved token. Public repositories still answer without one.'];
		}
		if ($httpStatus === 403 || $httpStatus === 429) {
			return ['limited', $host . ' refused the last request with HTTP ' . $httpStatus . ', usually a rate limit. A token raises the limit.'];
		}
		if ($httpStatus === 404) {
			return null;
		}

		return ['error', $host . ' answered HTTP ' . $httpStatus . '.'];
	}

	/**
	 * After an App Store catalogue fetch: report whether the store answered.
	 *
	 * @param bool $answered Whether any page came back as a readable catalogue.
	 * @param string $failure The last failure, when none did.
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function appStoreFetched(bool $answered, string $failure): bool {
		[$status, $message] = $this->describeAppStoreFetch($answered, $failure);

		return $this->reportThrottled(self::KEY_APPSTORE, $status, $message);
	}

	/**
	 * What a catalogue fetch says about the App Store connection.
	 *
	 * @param bool $answered Whether any page came back as a readable catalogue.
	 * @param string $failure The last failure, when none did.
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function describeAppStoreFetch(bool $answered, string $failure): array {
		if ($answered) {
			return ['configured', 'The App Store answered the last catalogue request.'];
		}

		$reason = $this->shorten($failure);
		if ($reason === '') {
			return ['error', 'The App Store did not answer the last catalogue request.'];
		}

		return ['error', 'The App Store did not answer the last catalogue request: ' . $reason];
	}

	/**
	 * After an advisory check: report how much of the feed was read.
	 *
	 * @param int $read How many advisories were read.
	 * @param string|null $error Why the read stopped, or null when it finished.
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function advisoryFeedRead(int $read, ?string $error): bool {
		[$status, $message] = $this->describeAdvisoryRead($read, $error);

		return $this->reportThrottled(self::KEY_ADVISORIES, $status, $message);
	}

	/**
	 * What an advisory check says about the advisory feed connection.
	 *
	 * @param int $read How many advisories were read.
	 * @param string|null $error Why the read stopped, or null when it finished.
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function describeAdvisoryRead(int $read, ?string $error): array {
		if ($error === null) {
			return ['configured', 'The last check read ' . $read . ' advisories from the feed.'];
		}

		if ($read > 0) {
			return ['limited', 'The last check stopped after ' . $read . ' advisories. ' . $this->shorten($error)];
		}

		return ['error', $this->shorten($error)];
	}

	/**
	 * After a GitHub token was saved: refresh, then report that GitHub accepted it.
	 *
	 * The token check has just reached GitHub, and GitHub accepted the token,
	 * so the report is `configured`. The refresh goes first: under hydra#674 a
	 * refresh retires older observations, so a report sent before it would be
	 * retired by it.
	 *
	 * @param string $forgeId The forge the token belongs to.
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function forgeTokenSaved(string $forgeId): bool {
		if ($forgeId !== self::REPORTED_FORGE) {
			return false;
		}

		if (!$this->refresh(self::KEY_GITHUB)) {
			return false;
		}

		return $this->report(self::KEY_GITHUB, 'configured', 'The token check reached GitHub, and GitHub accepted the token.');
	}

	/**
	 * After a GitHub token was removed: ask integriq to look again.
	 *
	 * @param string $forgeId The forge the token belonged to.
	 * @return bool True when the refresh was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	public function forgeTokenRemoved(string $forgeId): bool {
		if ($forgeId !== self::REPORTED_FORGE) {
			return false;
		}

		return $this->refresh(self::KEY_GITHUB);
	}

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-002-versioniq-reports-what-a-real-request-met
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (!class_exists($qualified)) {
			return null;
		}

		return $qualified;
	}

	/**
	 * Ask integriq to resolve one connection again, and forget the last status sent.
	 */
	private function refresh(string $key): bool {
		$eventClass = $this->resolveEventClass(self::REFRESH_EVENT);
		if ($eventClass === null) {
			return false;
		}

		$sent = $this->send($key, static fn (): object => new $eventClass(app: Application::APP_ID, key: $key));
		if ($sent) {
			$this->forget($key);
		}

		return $sent;
	}

	/**
	 * Report one status, unless the same status went out within the hour.
	 */
	private function reportThrottled(string $key, string $status, string $message): bool {
		if ($this->resolveEventClass(self::STATUS_EVENT) === null) {
			return false;
		}

		try {
			$memo = $this->appConfig->getValueString(Application::APP_ID, self::MEMO_PREFIX . $key, '');
		} catch (Throwable $e) {
			$this->logger->warning('Versioniq: could not read the last connection report', ['key' => $key, 'exception' => $e->getMessage()]);

			return false;
		}

		$parts = explode('|', $memo, 2);
		$lastStatus = $parts[0];
		$lastAt = (int)($parts[1] ?? '0');
		if ($lastStatus === $status && ($this->timeFactory->getTime() - $lastAt) < self::THROTTLE_SECONDS) {
			return false;
		}

		return $this->report($key, $status, $message);
	}

	/**
	 * Report one status for one connection, and remember it.
	 */
	private function report(string $key, string $status, string $message): bool {
		$eventClass = $this->resolveEventClass(self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		$sent = $this->send(
			$key,
			static fn (): object => new $eventClass(app: Application::APP_ID, key: $key, status: $status, message: $message)
		);
		if ($sent) {
			$this->remember($key, $status);
		}

		return $sent;
	}

	private function remember(string $key, string $status): void {
		try {
			$this->appConfig->setValueString(
				Application::APP_ID,
				self::MEMO_PREFIX . $key,
				$status . '|' . $this->timeFactory->getTime(),
			);
		} catch (Throwable $e) {
			$this->logger->warning('Versioniq: could not record a connection report', ['key' => $key, 'exception' => $e->getMessage()]);
		}
	}

	private function forget(string $key): void {
		try {
			$this->appConfig->deleteKey(Application::APP_ID, self::MEMO_PREFIX . $key);
		} catch (Throwable $e) {
			$this->logger->warning('Versioniq: could not clear a connection report', ['key' => $key, 'exception' => $e->getMessage()]);
		}
	}

	/**
	 * The host of a URL, so a message never carries a path, query or credentials.
	 */
	private function hostOf(string $url, string $fallback): string {
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return $fallback;
		}

		return $host;
	}

	/**
	 * A reason with every URL cut down to its host, then cut to REASON_LIMIT characters.
	 *
	 * An HTTP client's exception text carries the full request URL, and a
	 * feed or catalogue override can carry a path or credentials.
	 */
	private function shorten(string $text): string {
		$text = trim((string)preg_replace_callback(
			'~\b[a-z][a-z0-9+.-]*://[^\s<>"\']+~i',
			fn (array $match): string => $this->hostOf($match[0], 'a URL'),
			$text,
		));
		if (mb_strlen($text) <= self::REASON_LIMIT) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, self::REASON_LIMIT)) . '...';
	}

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param callable(): object $build Builds the event.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (!$event instanceof Event) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);

			return true;
		} catch (Throwable $e) {
			$this->logger->warning('Versioniq: could not send a connection event to integriq', ['key' => $key, 'exception' => $e->getMessage()]);

			return false;
		}
	}
}
