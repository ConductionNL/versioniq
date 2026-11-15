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

use OCA\Versioniq\Service\Audit\AuditOrigin;
use OCA\Versioniq\Service\Safety\InstalledCertificateCheck;
use OCA\Versioniq\Service\Safety\RevocationList;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily recheck of installed apps against a current revocation list (design
 * D2 of safety-withdrawn-and-revoked): fetches a newer list when one is due,
 * then checks every installed App Store app's certificate against it.
 *
 * @psalm-api
 */
class CertificateRecheckJob extends TimedJob {
	/** Once a day. */
	public const INTERVAL_SECONDS = 86400;

	public function __construct(
		ITimeFactory $time,
		private RevocationList $revocationList,
		private InstalledCertificateCheck $check,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	protected function run($argument): void {
		AuditOrigin::during(AuditOrigin::JOB, function (): void {
			try {
				$this->revocationList->refresh();
				$result = $this->check->run();
				$this->logger->info('CertificateRecheckJob: certificates rechecked', [
					'checked' => count($result['checked']),
					'revoked' => $result['revoked'],
					'disabled' => $result['disabled'],
				]);
			} catch (\Throwable $error) {
				$this->logger->error('CertificateRecheckJob: the recheck failed', ['message' => $error->getMessage()]);
			}
		});
	}
}
