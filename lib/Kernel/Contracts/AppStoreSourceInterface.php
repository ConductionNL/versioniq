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
 * The payload cache key prefixes of AppStoreSource that other components read,
 * so they depend on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppStoreSourceInterface {
	/**
	 * Key prefixes of the payload cache. Public so the repair step that marks
	 * existing rows lazy names the same keys AppStoreSource writes.
	 *
	 * EVERY READ AND WRITE OF THESE KEYS PASSES `lazy: true`. One payload per
	 * managed app, and a single one (mail) runs to ~4 MB, so the cache grows to
	 * tens of megabytes. Nextcloud loads every NON-lazy app-config row on every
	 * request, for every app, so an eager cache made the whole instance pay for
	 * it: measured 2026-10-06, 1,660 eager rows / 36 MB took ~300 ms of a
	 * ~450 ms `status.php`, and 55 ms once marked lazy.
	 *
	 * @see \OCA\Versioniq\Repair\MarkPayloadCacheLazy
	 */
	public const PAYLOAD_CACHE_PREFIX = 'appstore.payload.';

	public const PAYLOAD_CACHE_TS_PREFIX = 'appstore.payload_ts.';
}
