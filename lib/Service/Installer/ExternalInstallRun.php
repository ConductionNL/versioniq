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

use OCA\Versioniq\Service\Source\SourceBinding;

/**
 * The state of one external install while it runs: the request, the source
 * binding, the version installed before it and the app's enabled value read
 * before the download.
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class ExternalInstallRun {
	public string $previousEnabled = 'no';

	public function __construct(
		public readonly ExternalInstallRequest $request,
		public readonly SourceBinding $binding,
		public readonly string $installedVersion,
	) {
	}

	/**
	 * The version installed before this run, or null for a fresh install.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function fromVersion(): ?string {
		return $this->installedVersion === '' ? null : $this->installedVersion;
	}

	/**
	 * Whether the requested version is older than the installed one.
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 */
	public function isDowngrade(): bool {
		return $this->installedVersion !== '' && version_compare($this->request->version, $this->installedVersion, '<');
	}

	/**
	 * The enabled value the finalizer writes: "no" for a fresh install, else the value before the run.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function enabledAfter(): string {
		return $this->installedVersion === '' ? 'no' : $this->previousEnabled;
	}

	/**
	 * The SHA-256 recorded on the binding for the requested version, if any.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function recordedSha(): ?string {
		return $this->binding->getRecordedSha($this->request->version);
	}
}
