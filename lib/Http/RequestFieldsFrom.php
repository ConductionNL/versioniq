<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Http;

use Attribute;

/**
 * Names the RequestFields class whose properties a controller method reads as
 * its request parameters. The patched openapi-extractor (see
 * vendor-bin/openapi-extractor/patches) documents each property as if the
 * method declared it.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequestFieldsFrom {
	/**
	 * @param class-string<RequestFields> $fields
	 */
	public function __construct(
		public readonly string $fields,
	) {
	}
}
