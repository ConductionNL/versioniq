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

/**
 * What the webhook routes do with the targets (releases-new-release-alerts,
 * design D5): list, add, change, remove, and send a test message right away,
 * recording its outcome as the target's last delivery.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
 * @psalm-import-type TargetView from WebhookTargetStore
 */
class WebhookTargets {
	public const TEST_ALERT = ['kind' => 'test', 'subject' => 'test', 'parameters' => [], 'text' => 'Test message from Versioniq: this webhook target works.'];

	public function __construct(
		public readonly WebhookTargetStore $store,
		private WebhookSender $sender,
	) {
	}

	/**
	 * Send the test message; null when the target does not exist.
	 *
	 * @return TargetView|null The target with its new last delivery.
	 */
	public function test(string $id): ?array {
		$target = $this->store->forDelivery($id);
		if ($target === null) {
			return null;
		}

		$result = $this->sender->send($target, self::TEST_ALERT);
		$this->store->recordDelivery($id, $result['ok'], $result['error']);

		foreach ($this->store->all() as $view) {
			if ($view['id'] === $id) {
				return $view;
			}
		}

		throw new InvalidArgumentException('The target disappeared during the test.');
	}
}
