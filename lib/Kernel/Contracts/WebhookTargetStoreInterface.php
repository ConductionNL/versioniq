<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Kernel\Contracts;

/**
 * The public surface of WebhookTargetStore that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebhookTargetStoreInterface {
	/**
	 * One target with its decrypted secret, for delivery; null when absent or malformed.
	 *
	 * @return array{id: string, name: string, url: string, format: string, kinds: list<string>, enabled: bool, secret: ?string}|null
	 */
	public function forDelivery(string $id): ?array;

	/**
	 * Record the outcome of a delivery on the target.
	 *
	 * @param array{ok: bool, error: ?string} $result What WebhookSender::send() answered.
	 */
	public function recordDelivery(string $id, array $result): void;
}
