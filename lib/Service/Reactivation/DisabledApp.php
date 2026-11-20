<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Reactivation;

use OCA\Versioniq\Service\Audit\SeenStateStore;

/**
 * An app found disabled, with what Versioniq last saw of it and of the
 * server before the disable.
 *
 * @psalm-import-type SeenApp from SeenStateStore
 * @psalm-api
 */
final class DisabledApp {
	/**
	 * @param SeenApp|null $seen What was last seen of the app, before this disable.
	 * @param string $seenServer The server version last seen.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly ?array $seen,
		public readonly string $seenServer,
	) {
	}

	/**
	 * Whether Versioniq last saw the app enabled.
	 *
	 * @spec openspec/changes/audit-external-changes
	 */
	public function wasEnabled(): bool {
		return $this->seen !== null && $this->seen['enabled'];
	}
}
