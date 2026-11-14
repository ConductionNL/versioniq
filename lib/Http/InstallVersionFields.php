<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Http;

/**
 * The optional request fields of POST /api/app/{appId}/versions/{version}/install.
 */
final class InstallVersionFields extends RequestFields {
	/** @var string|null Override the bound source for this install only */
	public ?string $source = null;

	/** @var string `1` to include debug output; on its own it still implies a dry run (deprecated) */
	public string $debug = '0';

	/**
	 * THE NULL DEFAULT IS LOAD-BEARING: null means the caller left `dryRun`
	 * out, so the legacy `debug`-implies-dry-run fallback still applies, while
	 * an explicit `0` is a real install regardless of `debug`.
	 *
	 * @var string|null `1` to simulate, `0` to install for real. OMITTED is distinct from `0`: only when omitted does `debug=1` still imply a dry run
	 */
	public ?string $dryRun = null;

	/** @var string `1` to pin the app to this version after installing */
	public string $pin = '0';

	/** @var string `1` to accept a changed SHA-256 once and replace the recorded digest */
	public string $acceptNewSha = '0';

	/** @var string `1` to permit installing a version older than the installed one */
	public string $allowDowngrade = '0';

	/** @var string `1` to install past the version's declared maximum, through `app_install_overwrite` */
	public string $forceIncompatible = '0';
}
