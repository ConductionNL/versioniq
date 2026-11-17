<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Pat;

use OCA\Versioniq\Kernel\Contracts\PatExpiryEvaluatorInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Pure date-math over a PAT's `expiresAt`, shared by the API serialization
 * path (derived `expiryState`) and `PatExpiryWarningJob` (threshold
 * crossing). Days remaining are rounded up (a token expiring in 30 minutes
 * still counts as "1 day remaining", never "0 days" while still valid).
 *
 * @psalm-api
 */
class PatExpiryEvaluator implements PatExpiryEvaluatorInterface {
	/** Notify at ≤14 days remaining. */
	public const THRESHOLD_14D = '14d';
	/** Notify again at ≤3 days remaining. */
	public const THRESHOLD_3D = '3d';
	/** Notify once upon/after expiry. */
	public const THRESHOLD_EXPIRED = 'expired';

	public const STATE_OK = 'ok';
	public const STATE_EXPIRING = 'expiring';
	public const STATE_EXPIRED = 'expired';
	public const STATE_UNKNOWN = 'unknown';

	public function __construct(
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Derives the API/UI expiry state for a token; see "Expiry state in the PAT API and UI".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return array{state: 'ok'|'expiring'|'expired'|'unknown', daysRemaining: ?int}
	 */
	public function evaluate(?string $expiresAt): array {
		if ($expiresAt === null) {
			return ['state' => self::STATE_UNKNOWN, 'daysRemaining' => null];
		}

		$days = $this->daysRemaining($expiresAt);
		if ($days <= 0) {
			return ['state' => self::STATE_EXPIRED, 'daysRemaining' => $days];
		}
		if ($days <= 14) {
			return ['state' => self::STATE_EXPIRING, 'daysRemaining' => $days];
		}

		return ['state' => self::STATE_OK, 'daysRemaining' => $days];
	}

	/**
	 * Whole days remaining until `expiresAt` (negative once expired), rounded
	 * up so a partial day still counts as remaining; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function daysRemaining(string $expiresAt): int {
		$now = $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'));
		$expiry = new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC'));
		$diffSeconds = $expiry->getTimestamp() - $now->getTimestamp();

		return (int)ceil($diffSeconds / 86400);
	}

	/**
	 * Highest expiry-warning threshold crossed by a token right now, or null
	 * if none (unknown expiry, or still comfortably valid); see
	 * "PAT expiry warnings" ("Escalation at 3 days and at expiry").
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return self::THRESHOLD_*|null
	 */
	public function highestCrossedThreshold(?string $expiresAt): ?string {
		if ($expiresAt === null) {
			return null;
		}

		$days = $this->daysRemaining($expiresAt);
		if ($days <= 0) {
			return self::THRESHOLD_EXPIRED;
		}
		if ($days <= 3) {
			return self::THRESHOLD_3D;
		}
		if ($days <= 14) {
			return self::THRESHOLD_14D;
		}

		return null;
	}
}
