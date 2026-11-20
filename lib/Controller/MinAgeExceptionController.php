<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Controller;

use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\AutoUpdate\MinAgeExceptionStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Releases one held version of an app before the minimum age, or withdraws
 * that (releases-minimum-age D5). One exception per app; a new one replaces
 * the old. Both changes are admin-only, password-confirmed and audited; the
 * planner removes an exception by itself once it is no longer needed.
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 *
 * @psalm-api
 */
class MinAgeExceptionController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private MinAgeExceptionStore $exceptions,
		private AuditLogger $auditLogger,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Lets one version of an app through before the minimum age
	 *
	 * @param string $appId The app
	 * @param string $version The held version to release now
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, exception: array{version: string, by: string, at: string}}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The exception was stored
	 * 400: The version is empty
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/app/{appId}/min-age-exception/{version}')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/app/{appId}/min-age-exception/{version}', postfix: 'v1')]
	public function addException(string $appId, string $version): DataResponse {
		$uid = $this->caller->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		if (trim($version) === '') {
			return new DataResponse(['message' => 'version must not be empty.'], Http::STATUS_BAD_REQUEST);
		}

		$at = \DateTimeImmutable::createFromInterface($this->caller->timeFactory->getDateTime('now', new \DateTimeZone('UTC')));
		$replaced = $this->exceptions->get($appId);
		$this->exceptions->set($appId, $version, $uid, $at);
		$this->audit($uid, $appId, $version, sprintf(
			'Minimum-age exception for %s added%s',
			$version,
			$replaced !== null && $replaced['version'] !== $version ? ' (replaces ' . $replaced['version'] . ')' : '',
		));

		return new DataResponse([
			'appId' => $appId,
			'exception' => ['version' => $version, 'by' => $uid, 'at' => $at->format(DATE_ATOM)],
		]);
	}

	/**
	 * Withdraws an app's minimum-age exception for one version
	 *
	 * @param string $appId The app
	 * @param string $version The version the exception is for
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string, version: string, cleared: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The exception was removed
	 * 403: Caller is not an administrator
	 * 404: The app has no exception for that version
	 *
	 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/app/{appId}/min-age-exception/{version}')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/app/{appId}/min-age-exception/{version}', postfix: 'v1')]
	public function removeException(string $appId, string $version): DataResponse {
		$uid = $this->caller->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$stored = $this->exceptions->get($appId);
		if ($stored === null || $stored['version'] !== $version) {
			return new DataResponse(['message' => 'No minimum-age exception is recorded for that version.'], Http::STATUS_NOT_FOUND);
		}

		$this->exceptions->remove($appId);
		$this->audit($uid, $appId, $version, sprintf('Minimum-age exception for %s removed by hand', $version));

		return new DataResponse(['appId' => $appId, 'version' => $version, 'cleared' => true]);
	}

	/**
	 * One audit row per exception change.
	 */
	private function audit(string $uid, string $appId, string $version, string $message): void {
		$this->auditLogger->record(
			new AuditSubject($uid, $appId, AuditLogger::OPERATION_SETTINGS),
			new AuditedVersions(null, $version, null),
			AuditLogger::STATUS_SUCCESS,
			$message,
		);
	}
}
