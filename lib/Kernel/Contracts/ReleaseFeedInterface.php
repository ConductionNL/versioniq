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
 * The public surface of ReleaseFeed that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReleaseFeedInterface {
	/**
	 * One item per logged release, newest first.
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
	 */
	public function render(): string;
}
