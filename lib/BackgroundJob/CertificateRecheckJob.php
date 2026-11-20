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
use OCA\Versioniq\Service\Safety\RevokedAppResponder;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily: fetch a newer revocation list when one is due, check the signing
 * certificate of every installed App Store app against the newest trusted
 * list, and act on the revoked ones (safety-withdrawn-and-revoked, design D2).
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 *
 * @psalm-api
 */
class CertificateRecheckJob extends TimedJob {
	private const INTERVAL_SECONDS = 24 * 60 * 60;

	private LoggerInterface $logger;

	public function __construct(
		JobRuntime $runtime,
		private RevocationList $revocationList,
		private InstalledCertificateCheck $check,
		private RevokedAppResponder $responder,
	) {
		parent::__construct($runtime->time);
		$this->logger = $runtime->logger;
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		AuditOrigin::during(AuditOrigin::JOB, function (): void {
			$this->refreshList();
			try {
				$this->responder->respond($this->check->check($this->revocationList->current()));
			} catch (\Throwable $error) {
				$this->logger->warning('CertificateRecheckJob: the certificate recheck failed', ['message' => $error->getMessage()]);
			}
		});
	}

	/**
	 * Fetches a newer list when one is due; a failure is logged and the
	 * recheck goes on with the list in use.
	 */
	private function refreshList(): void {
		try {
			$this->revocationList->refreshIfDue();
		} catch (\Throwable $error) {
			$this->logger->warning('CertificateRecheckJob: the revocation list could not be refreshed; the list in use applies', ['message' => $error->getMessage()]);
		}
	}
}
