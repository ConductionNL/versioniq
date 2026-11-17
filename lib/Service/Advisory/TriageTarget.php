<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

/**
 * One advisory of one app (or of the server, `:server`) and the admin acting on it.
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 *
 * @psalm-api
 */
final class TriageTarget {
	/**
	 * @param string $appId The app id, or `:server`.
	 * @param string $advisoryId The advisory id.
	 * @param string $actor The uid of the admin acting.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $advisoryId,
		public readonly string $actor,
	) {
	}

	/**
	 * The key of this advisory in the triage map.
	 *
	 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
	 */
	public function key(): string {
		return AdvisoryTriageStore::key($this->appId, $this->advisoryId);
	}
}
