<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Alert;

use InvalidArgumentException;
use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ICrypto;

/**
 * The webhook targets (releases-new-release-alerts, design D5): one JSON value
 * per target under `webhook.{id}`, its last delivery under `webhook.{id}.last`.
 * The secret is stored encrypted with ICrypto and never leaves this class
 * except to the sender. Every change is audited as `settings`.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
 *
 * @psalm-type TargetView = array{id: string, name: string, url: string, format: string, kinds: list<string>, enabled: bool, hasSecret: bool, last: ?array{at: string, status: string, error: ?string}}
 */
class WebhookTargetStore {
	public const PREFIX = 'webhook.';

	private const LAST_SUFFIX = '.last';

	private const ID_PATTERN = '/^[a-z0-9_-]{1,32}$/';

	public const MAX_TARGETS = 20;

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private AuditLogger $auditLogger,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Every target as the page shows it, without its secret.
	 *
	 * @return list<TargetView>
	 */
	public function all(): array {
		$views = [];
		foreach ($this->ids() as $id) {
			$target = $this->stored($id);
			if ($target !== null) {
				$views[] = $this->view($id, $target);
			}
		}

		return $views;
	}

	/**
	 * One target with its decrypted secret, for delivery; null when absent or malformed.
	 *
	 * @return array{id: string, name: string, url: string, format: string, kinds: list<string>, enabled: bool, secret: ?string}|null
	 */
	public function forDelivery(string $id): ?array {
		$target = $this->stored($id);
		if ($target === null) {
			return null;
		}

		$secret = null;
		if (is_string($target['encryptedSecret'] ?? null) && $target['encryptedSecret'] !== '') {
			$secret = $this->crypto->decrypt($target['encryptedSecret']);
		}
		unset($target['encryptedSecret']);

		return ['id' => $id] + $target + ['secret' => $secret];
	}

	/**
	 * The ids of the enabled targets that receive a kind of alert.
	 *
	 * @return list<string>
	 */
	public function idsFor(string $kind): array {
		$ids = [];
		foreach ($this->ids() as $id) {
			$target = $this->stored($id);
			if ($target !== null && $target['enabled'] === true && in_array($kind, $target['kinds'], true)) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Add a target.
	 *
	 * @param array<string, mixed> $input
	 * @throws InvalidArgumentException on a field that is not valid, or a full list
	 * @return TargetView
	 */
	public function create(array $input, string $uid): array {
		if (count($this->ids()) >= self::MAX_TARGETS) {
			throw new InvalidArgumentException('At most ' . self::MAX_TARGETS . ' webhook targets can be added.');
		}

		$target = WebhookTargetInput::fields($input);
		$id = bin2hex(random_bytes(6));

		$view = $this->write($id, $target + ['encryptedSecret' => $this->encrypted(WebhookTargetInput::secret($input))]);
		$this->audit($uid, self::saved($id, $target));

		return $view;
	}

	/**
	 * Change a target; a missing or empty secret keeps the stored one, `clearSecret` removes it.
	 *
	 * @param array<string, mixed> $input
	 * @throws InvalidArgumentException on a field that is not valid
	 * @return TargetView|null The target, or null when it does not exist.
	 */
	public function update(string $id, array $input, string $uid): ?array {
		$current = $this->stored($id);
		if ($current === null) {
			return null;
		}

		$secret = WebhookTargetInput::secret($input);
		$encrypted = $secret === null ? ($current['encryptedSecret'] ?? null) : $this->encrypted($secret);
		if (($input['clearSecret'] ?? false) === true) {
			$encrypted = null;
		}

		$target = WebhookTargetInput::fields($input, $current);
		$view = $this->write($id, $target + ['encryptedSecret' => $encrypted]);
		$this->audit($uid, self::saved($id, $target));

		return $view;
	}

	/**
	 * Remove a target and its last delivery; false when it does not exist.
	 */
	public function delete(string $id, string $uid): bool {
		if ($this->stored($id) === null) {
			return false;
		}

		$this->config->deleteKey(Application::APP_ID, self::PREFIX . $id);
		$this->config->deleteKey(Application::APP_ID, self::PREFIX . $id . self::LAST_SUFFIX);
		$this->audit($uid, 'Webhook target ' . $id . ' removed');

		return true;
	}

	/**
	 * Record the outcome of a delivery on the target.
	 *
	 * @param array{ok: bool, error: ?string} $result What WebhookSender::send() answered.
	 */
	public function recordDelivery(string $id, array $result): void {
		if ($this->stored($id) === null) {
			return;
		}

		$last = [
			'at' => $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
			'status' => $result['ok'] ? 'succeeded' : 'failed',
			'error' => $result['ok'] ? null : $result['error'],
		];
		$this->config->setValueString(Application::APP_ID, self::PREFIX . $id . self::LAST_SUFFIX, json_encode($last, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param array<string, mixed> $target
	 * @return TargetView
	 */
	private function write(string $id, array $target): array {
		$this->config->setValueString(Application::APP_ID, self::PREFIX . $id, json_encode($target, JSON_THROW_ON_ERROR));

		return $this->view($id, $this->stored($id) ?? $target);
	}

	/**
	 * The audit line of a saved target.
	 *
	 * @param array{name: string, format: string, kinds: list<string>} $target
	 */
	private static function saved(string $id, array $target): string {
		return 'Webhook target ' . $id . ' saved: ' . $target['name'] . ' (' . $target['format'] . '; ' . implode(', ', $target['kinds']) . ')';
	}

	private function encrypted(?string $secret): ?string {
		return $secret === null ? null : $this->crypto->encrypt($secret);
	}

	/**
	 * The stored target ids, in key order.
	 *
	 * @return list<string>
	 */
	private function ids(): array {
		$ids = [];
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			$id = substr($key, strlen(self::PREFIX));
			if (str_starts_with($key, self::PREFIX) && preg_match(self::ID_PATTERN, $id) === 1) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * A stored target, or null when it is absent or not a valid target.
	 *
	 * @return array{name: string, url: string, format: string, kinds: list<string>, enabled: bool, encryptedSecret: ?string}|null
	 */
	private function stored(string $id): ?array {
		if (preg_match(self::ID_PATTERN, $id) !== 1) {
			return null;
		}

		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::PREFIX . $id, ''), true);
		if (!is_array($decoded)) {
			return null;
		}

		try {
			return WebhookTargetInput::stored($decoded) + ['encryptedSecret' => is_string($decoded['encryptedSecret'] ?? null) ? $decoded['encryptedSecret'] : null];
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $target
	 * @return TargetView
	 */
	private function view(string $id, array $target): array {
		return [
			'id' => $id,
			'name' => $target['name'],
			'url' => $target['url'],
			'format' => $target['format'],
			'kinds' => $target['kinds'],
			'enabled' => $target['enabled'],
			'hasSecret' => ($target['encryptedSecret'] ?? null) !== null,
			'last' => $this->lastDelivery($id),
		];
	}

	/**
	 * @return array{at: string, status: string, error: ?string}|null
	 */
	private function lastDelivery(string $id): ?array {
		$last = json_decode($this->config->getValueString(Application::APP_ID, self::PREFIX . $id . self::LAST_SUFFIX, ''), true);
		if (!is_array($last) || !is_string($last['at'] ?? null)) {
			return null;
		}

		return ['at' => $last['at'], 'status' => (string)($last['status'] ?? ''), 'error' => is_string($last['error'] ?? null) ? $last['error'] : null];
	}

	private function audit(string $uid, string $message): void {
		$this->auditLogger->record(
			new AuditSubject($uid, Application::APP_ID, AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
			'Settings changed: ' . $message,
		);
	}
}
