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
use OCA\Versioniq\Service\Alert\AlertServices;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The routes of the Alerts tab (releases-new-release-alerts): the release
 * alert scope and log (design D2), the follow list (D3) and the webhook
 * targets (D5). Admin-only; writes are password-confirmed and audited, and no
 * response carries a webhook secret.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-admins-are-notified-of-new-releases-in-the-scope-they-choose
 *
 * @psalm-api
 */
class AlertController extends OCSController {
	/** The body fields a webhook target write reads. */
	private const FIELDS = ['name', 'url', 'format', 'kinds', 'secret', 'clearSecret', 'enabled'];

	public function __construct(
		string $appName,
		IRequest $request,
		private AlertServices $alerts,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the notification scope, the logged releases (newest first) and the followed apps
	 *
	 * @return DataResponse<Http::STATUS_OK, array{scope: string, releases: list<array<string, mixed>>, follows: list<array{appId: string, sourceId: ?string, addedBy: string, addedAt: string}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The scope, the release log and the followed apps
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

		return new DataResponse([
			'scope' => $this->alerts->releases->scope(),
			'releases' => $this->alerts->releases->releases(),
			'follows' => $this->alerts->releases->follows(),
		]);
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
			$this->alerts->releases->setScope($scope, $uid);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['scope' => $this->alerts->releases->scope()]);
	}

	/**
	 * Follows the releases of an app that is not installed (password-confirmed, audited)
	 *
	 * @param string $appId The Nextcloud app id
	 * @param string $sourceId The source to watch: `appstore` or a forge source id; empty for the app's default source
	 *
	 * @return DataResponse<Http::STATUS_OK, array{follow: array{appId: string, sourceId: ?string, addedBy: string, addedAt: string}}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The app is followed
	 * 400: The app or source id is not valid, or the list is full; nothing was changed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-an-admin-follows-the-releases-of-an-app-that-is-not-installed
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/follow')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/follow', postfix: 'v1')]
	public function follow(string $appId = '', string $sourceId = ''): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new DataResponse(['follow' => $this->alerts->releases->follow($appId, $sourceId, $uid)]);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Stops following the releases of an app (password-confirmed, audited)
	 *
	 * @param string $appId The Nextcloud app id
	 *
	 * @return DataResponse<Http::STATUS_OK, array{appId: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The app is no longer followed
	 * 404: The app was not followed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-an-admin-follows-the-releases-of-an-app-that-is-not-installed
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/follow/{appId}')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/follow/{appId}', postfix: 'v1')]
	public function unfollow(string $appId): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if (!$this->alerts->releases->unfollow($appId, $uid)) {
			return new DataResponse(['message' => 'This app is not followed.'], Http::STATUS_NOT_FOUND);
		}

		return new DataResponse(['appId' => $appId]);
	}

	/**
	 * The caller's user id when they are an admin, else null.
	 */
	/**
	 * Lists the webhook targets with their last delivery, never their secret
	 *
	 * @return DataResponse<Http::STATUS_OK, array{targets: list<array<string, mixed>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The targets
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
	 */
	#[ApiRoute(verb: 'GET', url: '/api/webhooks')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/webhooks', postfix: 'v1')]
	public function webhooks(): DataResponse {
		if ($this->adminUid() === null) {
			return self::forbidden();
		}

		return new DataResponse(['targets' => $this->alerts->webhooks->store->all()]);
	}

	/**
	 * Adds a webhook target: name, https url, format (json or chat), kinds, optional secret and enabled (password-confirmed)
	 *
	 * @return DataResponse<Http::STATUS_OK, array{target: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The stored target
	 * 400: A field is not valid; nothing was stored
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/webhooks')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/webhooks', postfix: 'v1')]
	public function createWebhook(): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return self::forbidden();
		}

		try {
			return new DataResponse(['target' => $this->alerts->webhooks->store->create($this->body(), $uid)]);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Changes a webhook target; an empty secret keeps the stored one, clearSecret removes it (password-confirmed)
	 *
	 * @param string $id The target id
	 *
	 * @return DataResponse<Http::STATUS_OK, array{target: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The changed target
	 * 400: A field is not valid; nothing was changed
	 * 403: Caller is not an administrator
	 * 404: No such target
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/webhooks/{id}')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/webhooks/{id}', postfix: 'v1')]
	public function updateWebhook(string $id): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return self::forbidden();
		}

		try {
			$target = $this->alerts->webhooks->store->update($id, $this->body(), $uid);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $target === null ? self::notFound() : new DataResponse(['target' => $target]);
	}

	/**
	 * Removes a webhook target (password-confirmed)
	 *
	 * @param string $id The target id
	 *
	 * @return DataResponse<Http::STATUS_OK, array{id: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The target was removed
	 * 403: Caller is not an administrator
	 * 404: No such target
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/webhooks/{id}')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/webhooks/{id}', postfix: 'v1')]
	public function deleteWebhook(string $id): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return self::forbidden();
		}

		return $this->alerts->webhooks->store->delete($id, $uid) ? new DataResponse(['id' => $id]) : self::notFound();
	}

	/**
	 * Sends a test message to a webhook target now and records the outcome as its last delivery
	 *
	 * @param string $id The target id
	 *
	 * @return DataResponse<Http::STATUS_OK, array{target: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The target with the test's outcome as its last delivery
	 * 403: Caller is not an administrator
	 * 404: No such target
	 *
	 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
	 */
	#[ApiRoute(verb: 'POST', url: '/api/webhooks/{id}/test')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/webhooks/{id}/test', postfix: 'v1')]
	public function testWebhook(string $id): DataResponse {
		if ($this->adminUid() === null) {
			return self::forbidden();
		}

		$target = $this->alerts->webhooks->test($id);

		return $target === null ? self::notFound() : new DataResponse(['target' => $target]);
	}

	/**
	 * The target fields of the request body.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}

	private function adminUid(): ?string {
		$user = $this->caller->userSession->getUser();
		if ($user === null || !$this->caller->groupManager->isAdmin($user->getUID())) {
			return null;
		}

		return $user->getUID();
	}

	/**
	 * @return DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 */
	private static function forbidden(): DataResponse {
		return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}

	/**
	 * @return DataResponse<Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 */
	private static function notFound(): DataResponse {
		return new DataResponse(['message' => 'No such webhook target.'], Http::STATUS_NOT_FOUND);
	}
}
