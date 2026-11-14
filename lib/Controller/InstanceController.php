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
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Instance\InstanceConnection;
use OCA\Versioniq\Service\Instance\InstanceConnectionStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The connections to other Versioniq instances (install-multi-instance-promotion
 * design D1). Admin-only twice over, like SettingsController: no
 * NoAdminRequired attribute, and an explicit isAdmin() guard. Every change is
 * password-confirmed and audited as `instance_connection`. No response ever
 * carries a password.
 *
 * @psalm-suppress UnusedClass
 */
class InstanceController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private InstanceConnectionStore $store,
		private AuditLogger $auditLogger,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Lists this instance's stage and the connections, without their passwords
	 *
	 * @return DataResponse<Http::STATUS_OK, array{self: array{name: string, stage: string, order: int}, instances: list<array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: This instance and the connections in chain order
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	#[ApiRoute(verb: 'GET', url: '/api/instances')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/instances', postfix: 'v1')]
	public function index(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'self' => $this->store->self(),
			'instances' => array_map(static fn (InstanceConnection $connection): array => $connection->toPublicArray(), $this->store->all()),
		]);
	}

	/**
	 * Adds a connection to another instance (password-confirmed)
	 *
	 * @param string $id The connection id, lowercase letters, digits and hyphens
	 * @param string $name The name shown in the Instances tab
	 * @param string $stage The stage label, such as test, acceptance or production
	 * @param int $order The place in the chain, 0 to 99
	 * @param string $baseUrl The https address of that instance
	 * @param string $username An admin account on that instance
	 * @param string $password That account's app password; stored encrypted, never returned
	 * @return DataResponse<Http::STATUS_CREATED, array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_CONFLICT, array{message: string}, array{}>
	 *
	 * 201: The connection, without its password
	 * 400: A field is not acceptable; nothing was saved
	 * 403: Caller is not an administrator
	 * 409: A connection with this id exists
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/instances')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/instances', postfix: 'v1')]
	public function create(
		string $id = '',
		string $name = '',
		string $stage = '',
		int $order = 0,
		string $baseUrl = '',
		string $username = '',
		#[\SensitiveParameter]
		string $password = '',
	): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->store->get($id) !== null) {
			return new DataResponse(['message' => 'A connection with this id exists.'], Http::STATUS_CONFLICT);
		}

		$saved = $this->saved(['id' => $id, 'name' => $name, 'stage' => $stage, 'order' => $order, 'baseUrl' => $baseUrl, 'username' => $username], $password, 'added');
		if (is_string($saved)) {
			return new DataResponse(['message' => $saved], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($saved->toPublicArray(), Http::STATUS_CREATED);
	}

	/**
	 * Changes a connection (password-confirmed); an empty password keeps the stored one
	 *
	 * @param string $id The connection id
	 * @param string $name The name shown in the Instances tab
	 * @param string $stage The stage label
	 * @param int $order The place in the chain, 0 to 99
	 * @param string $baseUrl The https address of that instance
	 * @param string $username An admin account on that instance
	 * @param string $password A new app password, or empty to keep the stored one
	 * @return DataResponse<Http::STATUS_OK, array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The connection, without its password
	 * 400: A field is not acceptable; nothing was saved
	 * 403: Caller is not an administrator
	 * 404: No such connection
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/instances/{id}')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/instances/{id}', postfix: 'v1')]
	public function update(
		string $id,
		string $name = '',
		string $stage = '',
		int $order = 0,
		string $baseUrl = '',
		string $username = '',
		#[\SensitiveParameter]
		string $password = '',
	): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->store->get($id) === null) {
			return new DataResponse(['message' => 'No such connection.'], Http::STATUS_NOT_FOUND);
		}

		$saved = $this->saved(['id' => $id, 'name' => $name, 'stage' => $stage, 'order' => $order, 'baseUrl' => $baseUrl, 'username' => $username], $password, 'changed');
		if (is_string($saved)) {
			return new DataResponse(['message' => $saved], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($saved->toPublicArray(), Http::STATUS_OK);
	}

	/**
	 * Removes a connection and its stored password (password-confirmed)
	 *
	 * @param string $id The connection id
	 * @return DataResponse<Http::STATUS_OK, array{id: string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
	 *
	 * 200: The connection is gone
	 * 403: Caller is not an administrator
	 * 404: No such connection
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/instances/{id}')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/instances/{id}', postfix: 'v1')]
	public function destroy(string $id): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$connection = $this->store->get($id);
		if ($connection === null || !$this->store->delete($id)) {
			return new DataResponse(['message' => 'No such connection.'], Http::STATUS_NOT_FOUND);
		}

		$this->audit($connection, 'removed');

		return new DataResponse(['id' => $id]);
	}

	/**
	 * Save through the store and audit; a refused field gives the reason instead.
	 *
	 * @param array<string, mixed> $fields
	 * @return InstanceConnection|string the saved connection, or why it was refused
	 */
	private function saved(
		array $fields,
		#[\SensitiveParameter]
		string $password,
		string $verb,
	): InstanceConnection|string {
		try {
			$connection = $this->store->save($fields, $password, $this->actorUid());
		} catch (InvalidArgumentException $error) {
			return $error->getMessage();
		}

		$this->audit($connection, $verb);

		return $connection;
	}

	/**
	 * One audit row per change, naming the connection, its stage and address; never the password.
	 */
	private function audit(InstanceConnection $connection, string $verb): void {
		$this->auditLogger->record(
			new AuditSubject($this->actorUid(), 'versioniq', AuditLogger::OPERATION_INSTANCE_CONNECTION),
			new AuditedVersions(null, null, null),
			AuditLogger::STATUS_SUCCESS,
			sprintf('Connection %s %s: %s (%s), %s as %s', $connection->id, $verb, $connection->name, $connection->stage, $connection->baseUrl, $connection->username),
		);
	}

	private function actorUid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
