<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

use RuntimeException;

/**
 * A triage request the service refuses, with the HTTP status the route
 * answers it with: 400 for a value it cannot accept, 404 for an advisory the
 * stored snapshot does not hold for that app (advisories-triage, design D4).
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 *
 * @psalm-api
 */
final class TriageRefusal extends RuntimeException {
	/**
	 * A value the request carries that cannot be accepted.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public static function invalid(string $message): self {
		return new self($message, 400);
	}

	/**
	 * An advisory the stored snapshot does not hold for the app.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public static function notFound(): self {
		return new self('This advisory is not in the stored advisory check for this app.', 404);
	}

	/**
	 * The HTTP status the route answers with.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public function status(): int {
		return $this->getCode();
	}
}
