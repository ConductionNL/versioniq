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
 * One App Store install as a whole: the app, the resolved release, the version
 * installed before it and the app's enabled value before it.
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class SignedInstall {
	/**
	 * @param array<string, mixed> $release
	 */
	public function __construct(
		public readonly string $appId,
		public readonly array $release,
		public readonly string $installedVersion,
		public readonly string $previousEnabled,
	) {
	}

	/**
	 * The version installed before this install, or null for a fresh install.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function fromVersion(): ?string {
		return $this->installedVersion === '' ? null : $this->installedVersion;
	}

	/**
	 * The release's version when it is a string, else null.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function requestedVersion(): ?string {
		return isset($this->release['version']) && is_string($this->release['version']) ? $this->release['version'] : null;
	}

	/**
	 * The enabled value the finalizer writes: "no" for a fresh install, else the value before the install.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function enabledAfter(): string {
		return $this->installedVersion === '' ? 'no' : $this->previousEnabled;
	}
}
