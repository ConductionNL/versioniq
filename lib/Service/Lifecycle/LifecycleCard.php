<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Lifecycle;

/**
 * What the availability sweep stamps on an app card about its life cycle:
 * the signals (removed from the store, stale, archived, out of support) and,
 * when the successor list is wired, the successor the app can be replaced
 * with (inventory-lifecycle-signals, design D2 and D4).
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-abandoned-and-unsupported-apps-are-flagged-on-their-card
 *
 * @psalm-api
 */
final class LifecycleCard {
	public function __construct(
		public readonly LifecycleSignals $signals,
		public readonly ?SuccessorList $successors = null,
	) {
	}
}
