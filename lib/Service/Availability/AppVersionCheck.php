<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Availability;

/**
 * The app an update check is about: its installed version, whether it is
 * pinned, and the source its versions come from.
 */
final class AppVersionCheck {
	public function __construct(
		public readonly string $installedVersion,
		public readonly bool $pinned,
		public readonly ?string $sourceId,
	) {
	}

	/**
	 * The same check against another source.
	 *
	 * @spec openspec/specs/pending-updates/spec.md#requirement-the-installed-version-and-pending-updates-are-swept-into-a-snapshot
	 */
	public function withSource(?string $sourceId): self {
		return new self($this->installedVersion, $this->pinned, $sourceId);
	}
}
