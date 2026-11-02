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

use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Source\SourceBinding;

/**
 * The state of one install while it runs: the request, the source binding it
 * installs from, the version installed before it and the app's pin (read after the
 * downgrade guard).
 *
 * @spec openspec/specs/version-management/spec.md
 */
final class InstallRun {
	public function __construct(
		public readonly InstallRequest $request,
		public SourceBinding $binding,
		public readonly string $installedVersion,
		public ?Pin $pin = null,
	) {
	}

	/**
	 * The version installed before this run, or null for a fresh install.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	public function fromVersion(): ?string {
		return $this->installedVersion === '' ? null : $this->installedVersion;
	}

	/**
	 * The app's pin when the request installs a version other than the pinned one, else null.
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function pinBeingOverridden(): ?Pin {
		return $this->pin !== null && $this->request->target->version !== $this->pin->version ? $this->pin : null;
	}

	/**
	 * A `debug` entry holding `$log` when the request asked for debug output.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param array<array-key, mixed> $log
	 * @return array{debug?: array<array-key, mixed>}
	 */
	public function debugSuffix(array $log): array {
		return $this->request->options->includeDebug ? ['debug' => $log] : [];
	}
}
