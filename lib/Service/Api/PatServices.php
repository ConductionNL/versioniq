<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Api;

use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;

/**
 * Reads, stores, validates and ages personal access tokens.
 *
 * @psalm-api
 */
final class PatServices {
	public function __construct(
		public readonly PatMapper $patMapper,
		public readonly PatManager $patManager,
		public readonly PatValidator $patValidator,
		public readonly PatExpiryEvaluator $patExpiryEvaluator,
	) {
	}
}
