<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

use OCP\Util;

Util::addScript(OCA\Versioniq\AppInfo\Application::APP_ID, OCA\Versioniq\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Versioniq\AppInfo\Application::APP_ID, OCA\Versioniq\AppInfo\Application::APP_ID . '-main');

?>

<div id="versioniq"></div>
