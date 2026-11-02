<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Source;

/**
 * Finds the release source of an app: the binding store says which source an
 * app is bound to, the registry turns that binding into the source itself.
 *
 * @psalm-api
 */
final class SourceLookup {
	public function __construct(
		public readonly SourceRegistry $sourceRegistry,
		public readonly SourceBindingStore $bindingStore,
	) {
	}
}
