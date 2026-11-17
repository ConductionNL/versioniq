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

use OCA\Versioniq\Service\Installer\InstallRequest;

/**
 * The public surface of InstallerService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * @psalm-import-type InstalledAppCard from \OCA\Versioniq\Service\InstallerService
 */
interface InstallerServiceInterface {
	/**
	 * Returns the manageable apps enriched with metadata for frontend cards:
	 * enabled apps, installed-but-disabled apps and apps bound to a source but
	 * not installed yet, each with its `state` (`enabled`, `disabled`,
	 * `notInstalled`), including the last-known-good version record; see
	 * "List Installed Apps" and
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @return list<InstalledAppCard>
	 */
	public function getInstalledApps(): array;

	/**
	 * Resolves the active source and lists versions for an app; see "Fetch Available Versions", "Explicit source
	 * override" and "Version listings carry release notes".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return array{installedVersion: ?string, availableVersions: list<array{version:string, changelog:?string, recordedSha:?string, cachedOffline:bool}>, versions: list<array{version:string, changelog:?string, recordedSha:?string, cachedOffline:bool}>, source: string, sourceId: string, statusCode: int, hasError: bool, error?: string}
	 */
	public function getAppVersions(string $appId, ?string $sourceOverride = null): array;

	/**
	 * Installs a target version via the matching installer and persists the
	 * binding on success; see "Install Specific Version", "Source binding",
	 * and "Pins are enforced on Versioniq's own install path".
	 *
	 * `$overridePin` is `null` (no override requested), `repin`, or `unpin` —
	 * any other value is rejected with 400 by the caller before this method
	 * is reached in the normal flow, but is defensively rejected here too.
	 * `$pinRequested` pins the resulting version after a successful install
	 * when the app was not already pinned (atomic install-then-pin).
	 *
	 * `$acceptNewSha` bypasses, for this one request, a recorded-SHA-256
	 * mismatch on an external install and replaces the recorded digest on
	 * success — see "Recorded SHA-256 enforced on reinstall". Ignored for
	 * App Store (signed) installs, which do not record digests.
	 *
	 * `$allowDowngrade` acknowledges a downgrade (target version older than
	 * installed); without it a real (non-dry-run) downgrade is refused with
	 * a structured 409 before any download — see "Server-side downgrade
	 * guard". Dry-run requests evaluate the same condition but are never
	 * blocked by it.
	 *
	 * The request's dry-run flag is independent of its debug flag (which
	 * controls diagnostic verbosity only); see MODIFIED "Debug Mode". The
	 * app id and version are trimmed here.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @spec openspec/specs/cli-commands/spec.md
	 * @spec openspec/specs/artifact-cache/spec.md
	 * @return array{statusCode:int, payload:array<string, mixed>}
	 */
	public function installAppVersion(InstallRequest $request): array;

	/**
	 * Whether `$appId` may be installed/updated/listed through Versioniq —
	 * `false` for Versioniq itself (self-management) and any core /
	 * always-enabled app. Single shared predicate for the guard duplicated
	 * across {@see getAppVersions()}, {@see installAppVersion()}, and the
	 * `occ` commands; see "CLI trust context".
	 *
	 * @spec openspec/specs/cli-commands/spec.md
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function isManageableApp(string $appId): bool;
}
