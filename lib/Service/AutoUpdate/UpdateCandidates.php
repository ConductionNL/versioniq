<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\AutoUpdate;

use OCP\App\IAppManager;

/**
 * Picks the version an automatic update moves an app to: the app manager
 * reports the installed version, the selector chooses the candidate from the
 * available ones for the app's policy level.
 *
 * @psalm-api
 */
final class UpdateCandidates {
	public function __construct(
		public readonly IAppManager $appManager,
		public readonly CandidateSelector $candidateSelector,
	) {
	}
}
