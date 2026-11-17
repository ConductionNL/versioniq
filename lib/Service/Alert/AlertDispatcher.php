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

use OCA\Versioniq\BackgroundJob\WebhookDeliveryJob;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Queues one WebhookDeliveryJob per enabled target whose kinds include the
 * alert's kind (releases-new-release-alerts, design D6). It sends nothing
 * itself, so a slow or dead endpoint never delays the job or request that
 * raised the alert, and it never throws into the notifier that called it.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
 */
class AlertDispatcher {
	/** The alert kind of each notification subject. */
	public const KIND_BY_SUBJECT = [
		'new_release' => 'new_release',
		'pinned_to_vulnerable' => 'security',
		'advisory_digest' => 'security',
		'auto_update_success' => 'auto_update',
		'auto_update_failure' => 'auto_update',
		'pin_drift' => 'pin_drift',
		'pat_expiring' => 'token',
		'pat_expired' => 'token',
	];

	public function __construct(
		private WebhookTargetStore $targets,
		private IJobList $jobList,
		private AlertText $text,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Queue the alert of one notification subject for every matching target.
	 *
	 * @param array<string, mixed> $parameters The subject parameters.
	 */
	public function dispatch(string $subject, array $parameters): void {
		$kind = self::KIND_BY_SUBJECT[$subject] ?? null;
		if ($kind === null) {
			return;
		}

		try {
			$ids = $this->targets->idsFor($kind);
			if ($ids === []) {
				return;
			}

			$alert = ['kind' => $kind, 'subject' => $subject, 'parameters' => $parameters, 'text' => $this->text->english($subject, $parameters)];
			foreach ($ids as $id) {
				$this->jobList->add(WebhookDeliveryJob::class, ['targetId' => $id, 'alert' => $alert, 'attempt' => 1]);
			}
		} catch (\Throwable $error) {
			$this->logger->warning('AlertDispatcher: could not queue webhook deliveries', ['subject' => $subject, 'message' => $error->getMessage()]);
		}
	}
}
