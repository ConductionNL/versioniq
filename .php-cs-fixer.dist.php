<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

require_once './vendor-bin/cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('l10n')
	->notPath('node_modules')
	->notPath('src')
	->notPath('tests/stubs')
	->notPath('vendor')
	->in(__DIR__);

return $config;
