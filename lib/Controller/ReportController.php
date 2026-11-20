<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Controller;

use InvalidArgumentException;
use OCA\Versioniq\Service\Audit\AuditPeriod;
use OCA\Versioniq\Service\Report\SecurityReportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The security remediation report (audit-security-reports D6), for
 * administrators only, guarded like SettingsController.
 *
 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
 */
class ReportController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SecurityReportService $reports,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ITimeFactory $timeFactory,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The remediation report for a period of whole days in the admin's time
	 * zone: security updates, installs by status, per-severity figures
	 * against the deadlines, and a monthly trend.
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The report
	 * 400: The period is missing or cannot be read
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/audit-security-reports/specs/security-advisory-correlation/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/reports/security')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reports/security', postfix: 'v1')]
	public function security(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$from = trim((string)$this->request->getParam('from', ''));
		$to = trim((string)$this->request->getParam('to', ''));
		if ($from === '' || $to === '') {
			return new DataResponse(['message' => 'A report needs a from and a to date.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$period = AuditPeriod::fromDays($from, $to, trim((string)$this->request->getParam('timeZone', '')));
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$now = \DateTimeImmutable::createFromInterface($this->timeFactory->getDateTime('now', new \DateTimeZone('UTC')));

		return new DataResponse($this->reports->report($period, $now));
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
