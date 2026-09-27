<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the per-app source binding stored under app config key
 * `source.{appId}`. Bindings are JSON; absent or invalid values are treated
 * as unbound and the App Store is used as the fallback source.
 *
 * `set()` is the single write path for a source binding — it is called both
 * by the explicit `POST /api/source/{appId}/bind` endpoint and by the
 * implicit binding an install performs on success — so recording the
 * `bind_source` audit entry here covers both call sites with one hook and
 * guarantees exactly one entry per logical binding write; see "Source
 * binding changes are recorded".
 *
 * @psalm-api
 */
class SourceBindingStore {
	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the persisted source binding for an app (null if unbound/invalid); see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function get(string $appId): ?SourceBinding {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key($appId), '');
		if ($raw === '') {
			return null;
		}

		try {
			$decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		try {
			$binding = SourceBinding::fromArray($decoded);
		} catch (\InvalidArgumentException) {
			return null;
		}

		$this->logDroppedShaEntries($appId, $decoded, $binding);

		return $binding;
	}

	/**
	 * Persists a source binding under `source.{appId}`; see "Source binding",
	 * "Source binding changes are recorded" and "Recorded digests are
	 * binding-scoped and surfaced". Rebinding to the same source id preserves
	 * any digest recorded under the previous binding that the incoming
	 * `$binding` does not already carry; rebinding to a different source id
	 * discards them.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function set(string $appId, SourceBinding $binding): void {
		$previous = $this->get($appId);

		if ($previous !== null && $previous->getId() === $binding->getId()) {
			foreach ($previous->getRecordedShaMap() as $version => $sha) {
				if ($binding->getRecordedSha($version) === null) {
					$binding = $binding->withRecordedSha($version, $sha);
				}
			}
		}

		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($binding->toArray(), JSON_THROW_ON_ERROR)
		);

		$actorUid = $this->userSession->getUser()?->getUID() ?? 'system';
		$message = ($previous !== null && $previous->getId() !== $binding->getId())
			? sprintf('Rebound from %s', $previous->getId())
			: null;

		$this->auditLogger->record(
			$actorUid,
			$appId,
			AuditLogger::OPERATION_BIND_SOURCE,
			null,
			null,
			$binding->getId(),
			AuditLogger::STATUS_SUCCESS,
			$message,
		);
	}

	/**
	 * Removes an app's source binding; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function clear(string $appId): void {
		$this->config->deleteKey(Application::APP_ID, $this->key($appId));
	}

	/**
	 * Lists the ids of every app that has a persisted source binding, whether
	 * or not the app is installed, so the Apps tab can offer an app that was
	 * bound but not installed yet; see "List Installed Apps".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @return list<string>
	 */
	public function listBoundAppIds(): array {
		$appIds = [];
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			if (!str_starts_with($key, 'source.')) {
				continue;
			}
			$appId = substr($key, strlen('source.'));
			if ($appId !== '') {
				$appIds[] = $appId;
			}
		}

		return $appIds;
	}

	private function key(string $appId): string {
		return 'source.' . $appId;
	}

	/**
	 * Logs a warning when the raw persisted `sha256` map contained entries
	 * that {@see SourceBinding} dropped as invalid (malformed key/digest); see
	 * "Recorded digests are binding-scoped and surfaced".
	 *
	 * @param array<array-key, mixed> $decoded
	 */
	private function logDroppedShaEntries(string $appId, array $decoded, SourceBinding $binding): void {
		/** @var mixed $raw */
		$raw = $decoded['sha256'] ?? null;
		if (!is_array($raw)) {
			return;
		}

		$validCount = count($binding->getRecordedShaMap());
		if (count($raw) > $validCount) {
			$this->logger->warning('SourceBindingStore: dropped invalid recorded SHA-256 entries on read', [
				'appId' => $appId,
				'rawCount' => count($raw),
				'validCount' => $validCount,
			]);
		}
	}
}
