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
 * The failure categories of FailureClassifier that other components read, so
 * they depend on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FailureClassifierInterface {
	public const CATEGORY_PREFLIGHT_PERMISSION = 'preflight_permission';

	/**
	 * Not enough free disk space for the package (safety-rollback-backup-and-preflight D2).
	 */
	public const CATEGORY_PREFLIGHT_SPACE = 'preflight_space';

	public const CATEGORY_DOWNLOAD = 'download';

	public const CATEGORY_CHECKSUM_MISMATCH = 'checksum_mismatch';

	/**
	 * A recorded (trust-on-first-use) SHA-256 mismatch — distinct from
	 * {@see CATEGORY_CHECKSUM_MISMATCH} (the source-published sibling
	 * checksum): this is a history check the source cannot rewrite. See
	 * "Recorded SHA-256 enforced on reinstall".
	 */
	public const CATEGORY_SHA_MISMATCH = 'sha_mismatch';

	public const CATEGORY_EXTRACT = 'extract';

	public const CATEGORY_APPID_MISMATCH = 'appid_mismatch';

	public const CATEGORY_VERSION_MISMATCH = 'version_mismatch';

	public const CATEGORY_INCOMPATIBLE = 'incompatible';

	public const CATEGORY_FILESYSTEM = 'filesystem';

	public const CATEGORY_FINALIZE = 'finalize';

	/**
	 * A downgrade requested without `allowDowngrade: true` — see "Server-side
	 * downgrade guard". Distinct from every other category: nothing was
	 * attempted (no download, no filesystem change), the request is simply
	 * refused pending acknowledgement.
	 */
	public const CATEGORY_DOWNGRADE_GUARD = 'downgrade_guard';

	/**
	 * A dry run or an update check that must pass before an automatic install
	 * failed, so the nightly job did not install the version; see "The
	 * nightly job installs only after the attached checks pass".
	 */
	public const CATEGORY_CHECKS_FAILED = 'checks_failed';
	public const CATEGORY_UNKNOWN = 'unknown';
}
