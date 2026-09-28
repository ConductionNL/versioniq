<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

/**
 * Read-only driver that knows how to list and resolve releases for a given
 * source binding. Drivers do not perform any installation themselves —
 * installation is delegated to either `SelectedReleaseInstallerService`
 * (for the App Store path with full code-signing) or
 * `ExternalReleaseInstallerService` (for unsigned / external sources).
 *
 * @psalm-api
 */
interface SourceInterface {
	public const INSTALLER_SIGNED = 'signed';
	public const INSTALLER_EXTERNAL = 'external';

	public function getKind(): string;

	public function getInstallerKind(): string;

	/**
	 * Lists available versions for the given app under this source binding.
	 *
	 * Implementations MUST NOT throw on transient errors (rate limits,
	 * 404s, network failures); they MUST return an empty list with a
	 * populated `error` field in the result envelope so the caller can
	 * surface the message to the admin.
	 *
	 * Each entry carries a nullable `changelog` — the release notes for that
	 * version when the source provides them (App Store release translation,
	 * forge release body), `null` otherwise. Changelog extraction MUST be
	 * fail-soft: a mapping failure for one release yields `null` for that
	 * entry, never a failed listing.
	 *
	 * An entry MAY carry `serverCompatible`: whether the release runs on this
	 * server, or null when the source cannot tell. The App Store reads it from
	 * each release's `platformVersionSpec`.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 * @spec openspec/specs/version-management/spec.md
	 * @return array{versions: list<array{version: string, changelog: ?string, serverCompatible?: ?bool, releasedAt?: ?string}>, error: ?string}
	 */
	public function listVersions(string $appId, SourceBinding $binding): array;

	/**
	 * Resolves a single release into a payload usable by the installer.
	 *
	 * The shape varies by source kind:
	 *   - App Store releases include `download`, `signature`, `certificate`, `version`
	 *   - GitHub releases include `download`, `version`, optional `sha256Url`
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return array<string, mixed>|null
	 */
	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array;
}
