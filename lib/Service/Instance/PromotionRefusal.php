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

/**
 * Why a promotion cannot be planned: a machine-readable code, the HTTP status
 * it answers and the message for the admin (install-multi-instance-promotion design D6).
 *
 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
 */
final class PromotionRefusal {
	public function __construct(
		public readonly string $code,
		public readonly int $statusCode,
		public readonly string $message,
	) {
	}

	/**
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	public function toResult(): array {
		return ['statusCode' => $this->statusCode, 'payload' => ['code' => $this->code, 'message' => $this->message]];
	}
}
