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
use OCA\Versioniq\Kernel\Contracts\ManifestBuilderInterface;
use OCA\Versioniq\Service\Audit\AdminChangeAuditInterface;
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
	use ChecksAdmin;

	public function __construct(
		string $appName,
		IRequest $request,
		private InstanceConnectionStore $store,
		private AdminChangeAuditInterface $auditLogger,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ?ManifestBuilderInterface $manifest = null,
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
	 * This instance's manifest: its name and stage, server and Versioniq versions, and every app it runs
	 *
	 * @return DataResponse<Http::STATUS_OK, array{instanceName: string, stage: string, serverVersion: string, versioniqVersion: string, apps: list<array{appId: string, installedVersion: ?string, state: string, sourceId: ?string, sha256: ?string, runningSince: ?string}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_SERVICE_UNAVAILABLE, array{message: string}, array{}>
	 *
	 * 200: The manifest, read from local state only
	 * 403: Caller is not an administrator
	 * 503: The manifest cannot be built on this instance
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/design.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/instance/manifest')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/instance/manifest', postfix: 'v1')]
	public function manifest(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->manifest === null) {
			return new DataResponse(['message' => 'Unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new DataResponse($this->manifest->build());
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
	public function create(string $id = '', string $name = '', string $stage = '', int $order = 0, string $baseUrl = '', string $username = '', #[\SensitiveParameter] string $password = ''): DataResponse {
		return $this->write(fields: compact('id', 'name', 'stage', 'order', 'baseUrl', 'username', 'password'), creating: true);
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
	public function update(string $id, string $name = '', string $stage = '', int $order = 0, string $baseUrl = '', string $username = '', #[\SensitiveParameter] string $password = ''): DataResponse {
		return $this->write(fields: compact('id', 'name', 'stage', 'order', 'baseUrl', 'username', 'password'), creating: false);
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
	 * Add or change a connection for an administrator: 403 for anyone else,
	 * 409 when an added id exists, 404 when a changed one does not.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param array<string, mixed> $fields The connection's fields, its password included
	 * @param bool $creating Whether this adds the connection
	 */
	private function write(array $fields, bool $creating): DataResponse {
		$refused = $this->refusal(id: (string)$fields['id'], creating: $creating);
		if ($refused !== null) {
			return $refused;
		}

		[$verb, $status] = $creating ? ['added', Http::STATUS_CREATED] : ['changed', Http::STATUS_OK];

		return $this->respond(saved: $this->saved(fields: $fields, verb: $verb), status: $status);
	}

	/**
	 * Why a write may not go ahead, or null when it may.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-connects-other-instances-that-run-versioniq
	 * @param string $id The connection id
	 * @param bool $creating Whether this adds the connection
	 */
	private function refusal(string $id, bool $creating): ?DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$exists = $this->store->get($id) !== null;
		if ($creating !== $exists) {
			return null;
		}

		return $creating
			? new DataResponse(['message' => 'A connection with this id exists.'], Http::STATUS_CONFLICT)
			: new DataResponse(['message' => 'No such connection.'], Http::STATUS_NOT_FOUND);
	}

	/**
	 * Save through the store and audit; a refused field gives the reason instead.
	 *
	 * @param array<string, mixed> $fields The connection's fields, its password included
	 * @param string $verb How the audit entry names the change
	 * @return InstanceConnection|string the saved connection, or why it was refused
	 */
	private function saved(array $fields, string $verb): InstanceConnection|string {
		try {
			$connection = $this->store->save($fields, $this->actorUid());
		} catch (InvalidArgumentException $error) {
			return $error->getMessage();
		}

		$this->audit($connection, $verb);

		return $connection;
	}

	/**
	 * One audit row per change, naming the connection, its stage and address; never the password.
	 */
	/**
	 * The answer to a save: the connection without its password, or 400 with the reason.
	 *
	 * @param InstanceConnection|string $saved The saved connection, or why it was not saved.
	 * @param int $status The status of a saved connection.
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED, array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	private function respond(InstanceConnection|string $saved, int $status): DataResponse {
		if (is_string($saved)) {
			return new DataResponse(['message' => $saved], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($saved->toPublicArray(), $status);
	}

	private function audit(InstanceConnection $connection, string $verb): void {
		$this->auditLogger->recordAdminChange(
			$this->actorUid(),
			AdminChangeAuditInterface::OPERATION_INSTANCE_CONNECTION,
			sprintf('Connection %s %s: %s (%s), %s as %s', $connection->id, $verb, $connection->name, $connection->stage, $connection->baseUrl, $connection->username),
		);
	}

	private function actorUid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
