<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * The activity setting for version changes (audit-attribution-and-forwarding D4):
 * on for the stream, off for mail, and both changeable by each admin.
 *
 * @spec openspec/changes/audit-attribution-and-forwarding/specs/audit-trail/spec.md#requirement-audit-rows-reach-the-platforms-audit-log-and-activity-stream
 */
class Setting extends ActivitySettings {
	public function __construct(
		private IL10N $l,
	) {
	}

	public function getIdentifier(): string {
		return Provider::TYPE;
	}

	public function getName(): string {
		return $this->l->t('App versions installed, rolled back, pinned or given an update policy');
	}

	public function getGroupIdentifier(): string {
		return 'versioniq';
	}

	public function getGroupName(): string {
		return $this->l->t('Versioniq');
	}

	public function getPriority(): int {
		return 70;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
