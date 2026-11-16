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
 * The public surface of ManifestBuilder that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ManifestBuilderInterface {
	/**
	 * The manifest of this instance.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/design.md
	 * @return InstanceManifest
	 */
	public function build(): array;
}
