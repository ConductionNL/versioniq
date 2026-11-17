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

use OCA\Versioniq\Service\Advisory\ServerVersionProvider;

/**
 * The services behind the life cycle endpoints: the running server's
 * end-of-life date, the successor list and the replacement of an app with
 * its successor (inventory-lifecycle-signals, design D3 to D5).
 *
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 *
 * @psalm-api
 */
final class LifecycleServices {
	public function __construct(
		public readonly ServerEolFeed $eolFeed,
		public readonly ServerVersionProvider $serverVersion,
		public readonly SuccessorList $successors,
		public readonly AppReplacer $replacer,
	) {
	}
}
