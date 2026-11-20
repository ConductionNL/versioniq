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

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads how installs of an app went on each connected instance
 * (releases-version-facts design D2): one `GET` of the remote
 * `/api/app/{appId}/outcomes` per connection, with the connection's admin
 * account in Basic authentication, like RemoteInstanceReader. The decrypted
 * password lives only inside that call and never reaches a log line. Nothing
 * is cached: the picker asks once per version list, after the list renders.
 *
 * @psalm-api
 */
class RemoteOutcomesReader {
	public const OUTCOMES_PATH = '/ocs/v2.php/apps/versioniq/api/app/%s/outcomes';

	public const ERROR_OLDER_VERSIONIQ = 'This instance runs an older Versioniq without install outcomes.';
	public const ERROR_UNREADABLE = 'The instance did not answer with install outcomes.';

	public function __construct(
		private InstanceConnectionStore $store,
		private IClientService $clientService,
		private IConfig $systemConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The outcomes of one app on every connection, in chain order; a connection
	 * that cannot be read carries its error and does not stop the others.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return list<array{id: string, label: string, outcomes: ?array<string, array{succeeded: int, failed: int}>, error: ?string}>
	 */
	public function readAll(string $appId): array {
		return array_map(fn (InstanceConnection $connection): array => $this->read($connection, $appId), $this->store->all());
	}

	/**
	 * The outcomes of one app on one connection, or why they could not be read.
	 *
	 * @spec openspec/changes/releases-version-facts/specs/version-management/spec.md#requirement-every-version-shows-how-widely-it-runs-and-how-installs-of-it-went
	 * @return array{id: string, label: string, outcomes: ?array<string, array{succeeded: int, failed: int}>, error: ?string}
	 */
	public function read(InstanceConnection $connection, string $appId): array {
		$label = $connection->name !== '' ? $connection->name : $connection->id;
		try {
			$response = $this->store->withPassword($connection, fn (#[\SensitiveParameter] string $password): IResponse => $this->request($connection, $appId, $password));
		} catch (Throwable $error) {
			$this->logger->warning('RemoteOutcomesReader: outcomes read failed', [
				'connection' => $connection->id,
				'baseUrl' => $connection->baseUrl,
				'exception' => $error::class,
			]);

			return ['id' => $connection->id, 'label' => $label, 'outcomes' => null, 'error' => RemoteInstanceReader::ERROR_UNREACHABLE];
		}

		$answer = self::answer($response);

		return ['id' => $connection->id, 'label' => $label, 'outcomes' => $answer['outcomes'] ?? null, 'error' => $answer['error'] ?? null];
	}

	/**
	 * The GET of the remote outcomes endpoint with the connection's credential.
	 */
	private function request(InstanceConnection $connection, string $appId, #[\SensitiveParameter] string $password): IResponse {
		return $this->clientService->newClient()->get(rtrim($connection->baseUrl, '/') . sprintf(self::OUTCOMES_PATH, rawurlencode($appId)), [
			'auth' => [$connection->username, $password],
			'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
			'timeout' => RemoteInstanceReader::TIMEOUT_SECONDS,
			'http_errors' => false,
			'nextcloud' => ['allow_local_address' => $this->systemConfig->getSystemValueBool('allow_local_remote_servers', false)],
		]);
	}

	/**
	 * The outcomes in an answer, or why there are none.
	 *
	 * @return array{outcomes: array<string, array{succeeded: int, failed: int}>}|array{error: string}
	 */
	private static function answer(IResponse $response): array {
		$status = $response->getStatusCode();
		if (in_array($status, [401, 403], true)) {
			return ['error' => RemoteInstanceReader::ERROR_NOT_ADMIN];
		}

		if ($status === 404) {
			return ['error' => self::ERROR_OLDER_VERSIONIQ];
		}

		if ($status !== 200) {
			return ['error' => sprintf('The instance answered HTTP %d.', $status)];
		}

		$outcomes = self::outcomesOf((string)$response->getBody());

		return $outcomes === null ? ['error' => self::ERROR_UNREADABLE] : ['outcomes' => $outcomes];
	}

	/**
	 * The per-version counts an OCS answer body carries, or null when it carries
	 * no outcomes map. Counts that are not whole non-negative numbers read as zero.
	 *
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
}
