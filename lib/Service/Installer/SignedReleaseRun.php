<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Installer;

/**
 * One replacement of an app's files by a signed release: the app, the release
 * fields (swapped for the cached materials on a cache fallback) and the
 * temporary download paths.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class SignedReleaseRun {
	public function __construct(
		public readonly string $appId,
		public SignedRelease $release,
		public readonly TempArchive $temp,
	) {
	}
}
