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
 * (install-multi-instance-promotion design D4).
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
	public const TIMEOUT_SECONDS = 15;

	public const ERROR_NOT_ADMIN = 'This account is not an admin on that instance.';
	public const ERROR_OLDER_VERSIONIQ = 'This instance runs an older Versioniq without instance support.';
	public const ERROR_UNREACHABLE = 'The instance could not be reached.';
	public const ERROR_UNREADABLE = 'The instance did not answer with a manifest.';

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
	 * One request to the remote manifest endpoint.
	 *
	 * @return array{manifest: array<string, mixed>}|array{error: string}
	 */
	private function read(InstanceConnection $connection): array {
		$url = rtrim($connection->baseUrl, '/') . self::MANIFEST_PATH;
		try {
			$response = $this->store->withPassword(
				$connection,
				fn (string $password): IResponse => $this->clientService->newClient()->get($url, [
					'auth' => [$connection->username, $password],
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'timeout' => self::TIMEOUT_SECONDS,
					'http_errors' => false,
					'nextcloud' => ['allow_local_address' => $this->systemConfig->getSystemValueBool('allow_local_remote_servers', false)],
				]),
			);
		} catch (Throwable $error) {
			$this->logger->warning('RemoteInstanceReader: manifest read failed', [
				'connection' => $connection->id,
				'baseUrl' => $connection->baseUrl,
				'exception' => $error::class,
			]);

			return ['error' => self::ERROR_UNREACHABLE];
		}

		return $this->answer($response);
	}

	/**
	 * The manifest in an answer, or why there is none.
	 *
	 * @return array{manifest: array<string, mixed>}|array{error: string}
	 */
	private function answer(IResponse $response): array {
		$status = $response->getStatusCode();
		if ($status === 401 || $status === 403) {
			return ['error' => self::ERROR_NOT_ADMIN];
		}

		if ($status === 404) {
			return ['error' => self::ERROR_OLDER_VERSIONIQ];
		}

		/** @var mixed $body */
		$body = json_decode((string)$response->getBody(), true);
		$manifest = is_array($body) ? ($body['ocs']['data'] ?? null) : null;
		if ($status !== 200 || !is_array($manifest) || !is_array($manifest['apps'] ?? null)) {
			return ['error' => $status === 200 ? self::ERROR_UNREADABLE : sprintf('The instance answered HTTP %d.', $status)];
		}

		return ['manifest' => self::arrayOrNull($manifest) ?? []];
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
