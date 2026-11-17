<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Alert;

use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;

/**
 * What the Alerts tab's routes work with (releases-new-release-alerts, design
 * D2, D3 and D5): the release alert settings with the follow list, and the
 * webhook targets. Held together so AlertController keeps four collaborators.
 */
final class AlertServices {
	public function __construct(
		public readonly ReleaseAlertSettings $releases,
		public readonly WebhookTargets $webhooks,
	) {
	}
}
