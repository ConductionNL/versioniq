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
 * The public surface of WebhookSender that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * @psalm-import-type Alert from \OCA\Versioniq\Service\Alert\WebhookSender
 */
interface WebhookSenderInterface {
	/**
	 * Send the alert; the outcome and, on failure, why.
	 *
	 * @param array{url: string, format: string, secret: ?string} $target
	 * @param Alert $alert
	 * @return array{ok: bool, error: ?string}
	 */
	public function send(array $target, array $alert): array;
}
