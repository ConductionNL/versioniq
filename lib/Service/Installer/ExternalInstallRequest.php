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
 * The app, the version, the resolved release and the flags of one external install.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ExternalInstallRequest {
	/**
	 * @param array<string, mixed> $release
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $version,
		public readonly array $release,
		public readonly ExternalInstallOptions $options,
	) {
	}

	/**
	 * The release's archive URL, or an empty string when it has none.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function downloadUrl(): string {
		return isset($this->release['download']) && is_string($this->release['download']) ? $this->release['download'] : '';
	}

	/**
	 * The release's sibling `.sha256` URL, or null when it has none.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function shaUrl(): ?string {
		return isset($this->release['sha256Url']) && is_string($this->release['sha256Url']) && $this->release['sha256Url'] !== ''
			? $this->release['sha256Url']
			: null;
	}
}
