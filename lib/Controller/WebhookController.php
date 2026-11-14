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
use OCA\Versioniq\Service\Alert\WebhookTargets;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * The webhook targets of the Alerts tab (releases-new-release-alerts, design
 * D5): admin-only, writes password-confirmed and audited, and no response
 * carries a secret. Kept apart from AlertController so neither grows past
 * four collaborators.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
 *
 * @psalm-api
 */
class WebhookController extends OCSController {
	/** The body fields a target write reads. */
	private const FIELDS = ['name', 'url', 'format', 'kinds', 'secret', 'clearSecret', 'enabled'];

	public function __construct(
		string $appName,
		IRequest $request,
		private WebhookTargets $targets,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

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
	public function index(): DataResponse {
		if ($this->adminUid() === null) {
			return self::forbidden();
		}

		return new DataResponse(['targets' => $this->targets->store->all()]);
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
	public function create(): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return self::forbidden();
		}

		try {
			return new DataResponse(['target' => $this->targets->store->create($this->body(), $uid)]);
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
	public function update(string $id): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return self::forbidden();
		}

		try {
			$target = $this->targets->store->update($id, $this->body(), $uid);
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
	public function destroy(string $id): DataResponse {
		$uid = $this->adminUid();
		if ($uid === null) {
			return self::forbidden();
		}

		return $this->targets->store->delete($id, $uid) ? new DataResponse(['id' => $id]) : self::notFound();
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
	public function test(string $id): DataResponse {
		if ($this->adminUid() === null) {
			return self::forbidden();
		}

		$target = $this->targets->test($id);

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
