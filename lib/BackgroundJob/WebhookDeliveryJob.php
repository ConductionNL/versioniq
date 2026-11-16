<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\BackgroundJob;

use OCA\Versioniq\Kernel\Contracts\WebhookSenderInterface;
use OCA\Versioniq\Kernel\Contracts\WebhookTargetStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Delivers one alert to one webhook target (releases-new-release-alerts,
 * design D6). A target that was removed or switched off is skipped. On a non-2xx
 * answer the job queues itself again, up to three attempts in all, and then
 * records the failure on the target; a success is recorded as well.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
 */
class WebhookDeliveryJob extends QueuedJob {
	public const MAX_ATTEMPTS = 3;

	public function __construct(
		ITimeFactory $time,
		private WebhookTargetStoreInterface $targets,
		private WebhookSenderInterface $sender,
		private IJobList $jobList,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `{targetId, alert, attempt}`.
	 */
	protected function run($argument): void {
		$target = is_array($argument) && is_string($argument['targetId'] ?? null) ? $this->targets->forDelivery($argument['targetId']) : null;
		if ($target === null || $target['enabled'] !== true || !is_array($argument['alert'] ?? null)) {
			return;
		}

		$result = $this->sender->send($target, $argument['alert']);
		$attempt = (int)($argument['attempt'] ?? 1);
		if (!$result['ok'] && $attempt < self::MAX_ATTEMPTS) {
			$this->jobList->add(self::class, ['attempt' => $attempt + 1] + $argument);

			return;
		}

		$this->targets->recordDelivery($target['id'], $result);
	}
}
