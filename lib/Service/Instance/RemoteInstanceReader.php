<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Instance;

use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads another instance's manifest and keeps the last answer
 * (install-multi-instance-promotion design D4), and reads how installs of an
 * app went there (releases-version-facts design D2).
 *
 * One `GET` per read, with the connection's admin account in Basic
 * authentication; the decrypted password lives only inside that call and never
 * reaches a log line. The result is cached under `instance.{id}.manifest`. A
 * read that fails keeps the previous manifest and its read time, next to the
 * error and when it happened.
 *
 * @psalm-api
 */
class RemoteInstanceReader {
	public const MANIFEST_PATH = '/ocs/v2.php/apps/versioniq/api/instance/manifest';
	public const OUTCOMES_PATH = '/ocs/v2.php/apps/versioniq/api/app/%s/outcomes';
	public const TIMEOUT_SECONDS = 15;

	public const ERROR_NOT_ADMIN = 'This account is not an admin on that instance.';
	public const ERROR_OLDER_VERSIONIQ = 'This instance runs an older Versioniq without instance support.';
	public const ERROR_UNREACHABLE = 'The instance could not be reached.';
	public const ERROR_UNREADABLE = 'The instance did not answer with a manifest.';
	public const ERROR_NO_OUTCOMES = 'This instance runs an older Versioniq without install outcomes.';
	public const ERROR_UNREADABLE_OUTCOMES = 'The instance did not answer with install outcomes.';

	public function __construct(
		private InstanceConnectionStore $store,
		private IClientService $clientService,
		private IConfig $systemConfig,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Read the connection's manifest now, cache it and return the cached record.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
	 * @return array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}
	 */
	public function fetch(InstanceConnection $connection): array {
		$previous = $this->cached($connection);
		$now = $this->now();
		$answer = $this->read($connection);

		$record = isset($answer['manifest'])
			? ['manifest' => $answer['manifest'], 'fetchedAt' => $now, 'error' => null, 'checkedAt' => $now]
			: ['manifest' => $previous['manifest'], 'fetchedAt' => $previous['fetchedAt'], 'error' => $answer['error'] ?? self::ERROR_UNREADABLE, 'checkedAt' => $now];

		$this->appConfig->setValueString(Application::APP_ID, $this->cacheKey($connection), (string)json_encode($record));

		return $record;
	}

	/**
	 * The last cached record of a connection; all null when it was never read.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
	 * @return array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}
	 */
	public function cached(InstanceConnection $connection): array {
		/** @var mixed $decoded */
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, $this->cacheKey($connection), ''), true);
		$stored = is_array($decoded) ? $decoded : [];

		return [
			'manifest' => self::arrayOrNull($stored['manifest'] ?? null),
			'fetchedAt' => self::textOrNull($stored['fetchedAt'] ?? null),
			'error' => self::textOrNull($stored['error'] ?? null),
			'checkedAt' => self::textOrNull($stored['checkedAt'] ?? null),
		];
	}

	/**
	 * How installs of one app went on every connection, in chain order; a
	 * connection that cannot be read carries its error and does not stop the others.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return list<array{id: string, label: string, outcomes: ?array<string, array{succeeded: int, failed: int}>, error: ?string}>
	 */
	public function allOutcomes(string $appId): array {
		return array_map(fn (InstanceConnection $connection): array => $this->outcomes($connection, $appId), $this->store->all());
	}

	/**
	 * How installs of one app went on one connection, read now and not cached,
	 * or why they could not be read.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return array{id: string, label: string, outcomes: ?array<string, array{succeeded: int, failed: int}>, error: ?string}
	 */
	public function outcomes(InstanceConnection $connection, string $appId): array {
		$response = $this->send($connection, sprintf(self::OUTCOMES_PATH, rawurlencode($appId)));
		$answer = $response === null ? ['error' => self::ERROR_UNREACHABLE] : self::outcomesAnswer($response);

		return [
			'id' => $connection->id,
			'label' => $connection->name !== '' ? $connection->name : $connection->id,
			'outcomes' => $answer['outcomes'] ?? null,
			'error' => $answer['error'] ?? null,
		];
	}

	/**
	 * One request to the remote manifest endpoint.
	 *
	 * @return array{manifest: array<string, mixed>}|array{error: string}
	 */
	private function read(InstanceConnection $connection): array {
		$response = $this->send($connection, self::MANIFEST_PATH);

		return $response === null ? ['error' => self::ERROR_UNREACHABLE] : self::answer($response);
	}

	/**
	 * One GET of a remote path with the connection's credential; null when it
	 * throws, which is logged without the password.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 */
	private function send(InstanceConnection $connection, string $path): ?IResponse {
		try {
			return $this->store->withPassword($connection, fn (#[\SensitiveParameter] string $password): IResponse => $this->clientService->newClient()->get(
				rtrim($connection->baseUrl, '/') . $path,
				$this->requestOptions($connection->username, $password),
			));
		} catch (Throwable $error) {
			$this->logger->warning('RemoteInstanceReader: read failed', [
				'connection' => $connection->id,
				'baseUrl' => $connection->baseUrl,
				'path' => $path,
				'exception' => $error::class,
			]);

			return null;
		}
	}

	/**
	 * The options of an authenticated OCS GET.
	 *
	 * @return array<string, mixed>
	 */
	private function requestOptions(string $username, #[\SensitiveParameter] string $password): array {
		return [
			'auth' => [$username, $password],
			'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
			'timeout' => self::TIMEOUT_SECONDS,
			'http_errors' => false,
			'nextcloud' => ['allow_local_address' => $this->systemConfig->getSystemValueBool('allow_local_remote_servers', false)],
		];
	}

	/**
	 * The outcomes in an answer, or why there are none.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return array{outcomes: array<string, array{succeeded: int, failed: int}>}|array{error: string}
	 */
	private static function outcomesAnswer(IResponse $response): array {
		$status = $response->getStatusCode();
		$error = $status === 404 ? self::ERROR_NO_OUTCOMES : self::statusError($status);
		if ($error !== null) {
			return ['error' => $error];
		}

		$outcomes = self::outcomesOf((string)$response->getBody());

		return $outcomes === null ? ['error' => self::ERROR_UNREADABLE_OUTCOMES] : ['outcomes' => $outcomes];
	}

	/**
	 * The per-version counts an OCS answer body carries, or null when it carries
	 * no outcomes map. Counts that are not whole positive numbers read as zero.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return array<string, array{succeeded: int, failed: int}>|null
	 */
	private static function outcomesOf(string $body): ?array {
		/** @var mixed $decoded */
		$decoded = json_decode($body, true);
		/** @var mixed $ocs */
		$ocs = is_array($decoded) ? ($decoded['ocs'] ?? null) : null;
		/** @var mixed $data */
		$data = is_array($ocs) ? ($ocs['data'] ?? null) : null;
		/** @var mixed $outcomes */
		$outcomes = is_array($data) ? ($data['outcomes'] ?? null) : null;
		if (!is_array($outcomes)) {
			return null;
		}

		$counts = [];
		/** @var mixed $entry */
		foreach ($outcomes as $version => $entry) {
			if (is_array($entry)) {
				$counts[(string)$version] = ['succeeded' => self::count($entry['succeeded'] ?? 0), 'failed' => self::count($entry['failed'] ?? 0)];
			}
		}

		return $counts;
	}

	private static function count(mixed $value): int {
		return is_int($value) && $value > 0 ? $value : 0;
	}

	/**
	 * The manifest in an answer, or why there is none.
	 *
	 * @return array{manifest: array<string, mixed>}|array{error: string}
	 */
	private static function answer(IResponse $response): array {
		$error = self::statusError($response->getStatusCode());
		if ($error !== null) {
			return ['error' => $error];
		}

		$manifest = self::manifestOf((string)$response->getBody());

		return $manifest === null ? ['error' => self::ERROR_UNREADABLE] : ['manifest' => $manifest];
	}

	/**
	 * Why an answer with this status carries no manifest; null for 200.
	 */
	private static function statusError(int $status): ?string {
		if (in_array($status, [401, 403], true)) {
			return self::ERROR_NOT_ADMIN;
		}

		if ($status === 404) {
			return self::ERROR_OLDER_VERSIONIQ;
		}

		return $status === 200 ? null : sprintf('The instance answered HTTP %d.', $status);
	}

	/**
	 * The manifest an OCS answer body carries, or null when it carries none with an apps list.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function manifestOf(string $body): ?array {
		/** @var mixed $decoded */
		$decoded = json_decode($body, true);
		$manifest = is_array($decoded) ? ($decoded['ocs']['data'] ?? null) : null;
		if (!is_array($manifest) || !is_array($manifest['apps'] ?? null)) {
			return null;
		}

		return self::arrayOrNull($manifest) ?? [];
	}

	private function cacheKey(InstanceConnection $connection): string {
		return InstanceConnectionStore::KEY_PREFIX . $connection->id . '.manifest';
	}

	private function now(): string {
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function arrayOrNull(mixed $value): ?array {
		if (!is_array($value)) {
			return null;
		}

		/** @var array<string, mixed> $value */
		return $value;
	}

	private static function textOrNull(mixed $value): ?string {
		return is_string($value) && $value !== '' ? $value : null;
	}
}
