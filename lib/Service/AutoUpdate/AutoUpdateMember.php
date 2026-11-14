<?php

declare(strict_types=1);

/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

/**
 * One app in a plan: what is installed, what it would move to, and why it
 * will not when it will not (autoupdate-run-batching D1).
 *
 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
 */
final class AutoUpdateMember {
	/** The app is pinned; it is skipped without asking its source. */
	public const REASON_PINNED = 'pinned';

	/** No release qualifies under the policy; the app is current. */
	public const REASON_NO_CANDIDATE = 'no_candidate';

	/** The release source did not answer. */
	public const REASON_SOURCE_ERROR = 'source_error';

	/** The candidate was attempted before and is never retried. */
	public const REASON_BLOCKED = 'blocked';

	/** A run or weekly limit holds it back. */
	public const REASON_LIMIT = 'limit';

	/**
	 * @param string      $appId            The app.
	 * @param string|null $installedVersion What is installed, when it was read.
	 * @param string|null $candidate        The version it would move to.
	 * @param string|null $reason           Why it will not install, or null when it will.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly ?string $installedVersion,
		public readonly ?string $candidate,
		public readonly ?string $reason,
	) {
	}

	/**
	 * Whether this member installs in this run.
	 *
	 * @spec openspec/changes/autoupdate-run-batching/specs/auto-update-policies/spec.md
	 */
	public function willInstall(): bool {
		return $this->reason === null && $this->candidate !== null && $this->installedVersion !== null;
	}
}
