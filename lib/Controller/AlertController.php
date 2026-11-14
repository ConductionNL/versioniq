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
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The release alert settings and log for the Alerts tab
 * (releases-new-release-alerts, design D2).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 *
 * @psalm-api
 */
class AlertController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ReleaseAlertSettings $settings,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the notification scope and the logged releases, newest first
	 *
	 * @return DataResponse<Http::STATUS_OK, array{scope: string, releases: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The scope and the release log
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 */
	#[ApiRoute(verb: 'GET', url: '/api/release-alerts')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/release-alerts', postfix: 'v1')]
	public function releaseAlerts(): DataResponse {
		if ($this->adminUid() === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['scope' => $this->settings->scope(), 'releases' => $this->settings->releases()]);
	}

	/**
	 * Sets which new releases reach the admins' notifications (password-confirmed)
	 *
	 * @param string $scope forge, all or off
	 *
	 * @return DataResponse<Http::STATUS_OK, array{scope: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The scope after the change
	 * 400: The scope is not forge, all or off; nothing was changed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/release-alerts/scope')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/release-alerts/scope', postfix: 'v1')]
	public function setReleaseScope(string $scope = ''): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->settings->setScope($scope, $uid);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['scope' => $this->settings->scope()]);
	}

	/**
	 * The caller's user id when they are an admin, else null.
	 */
	private function adminUid(): ?string {
		$user = $this->caller->userSession->getUser();
		if ($user === null || !$this->caller->groupManager->isAdmin($user->getUID())) {
			return null;
		}

		return $user->getUID();
	}
}
