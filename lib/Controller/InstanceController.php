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
use OCA\Versioniq\Service\Instance\PromotionRefusal;
use OCA\Versioniq\Service\Instance\PromotionService;
use OCA\Versioniq\Service\Instance\RemoteInstanceReader;
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
		private ?RemoteInstanceReader $reader = null,
		private ?PromotionService $promotions = null,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Lists this instance's stage and the connections, without their passwords
	 *
	 * @return DataResponse<Http::STATUS_OK, array{self: array{name: string, stage: string, order: int}, instances: list<array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string, remote?: array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
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
			'instances' => array_map(fn (InstanceConnection $connection): array => $this->withRemote($connection, $this->reader?->cached($connection)), $this->store->all()),
		]);
	}

	/**
	 * Reads every connection's manifest once; one that fails keeps its last manifest and does not stop the others
	 *
	 * @return DataResponse<Http::STATUS_OK, array{self: array{name: string, stage: string, order: int}, instances: list<array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string, remote?: array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_SERVICE_UNAVAILABLE, array{message: string}, array{}>
	 *
	 * 200: This instance and the connections, each with its manifest as read now
	 * 403: Caller is not an administrator
	 * 503: Remote reads are not available on this instance
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
	 */
	#[ApiRoute(verb: 'POST', url: '/api/instances/refresh')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/instances/refresh', postfix: 'v1')]
	public function refresh(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->reader === null) {
			return new DataResponse(['message' => 'Unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new DataResponse([
			'self' => $this->store->self(),
			'instances' => array_map(fn (InstanceConnection $connection): array => $this->withRemote($connection, $this->readNow($connection)), $this->store->all()),
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
	 * What promoting an app from a connection would install, from its cached manifest
	 *
	 * @param string $appId The app to promote
	 * @param string $connection The connection id of the earlier stage
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_BAD_GATEWAY|Http::STATUS_SERVICE_UNAVAILABLE, array{code?: string, message: string}, array{}>
	 *
	 * 200: The version, source, digest, direction and waiting-period verdict
	 * 403: Caller is not an administrator
	 * 404: No such connection
	 * 409: The connection does not run this app
	 * 502: The connection has no readable manifest
	 * 503: Promotion is not available on this instance
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
	 * @psalm-suppress InvalidReturnType
	 */
	#[ApiRoute(verb: 'GET', url: '/api/app/{appId}/promote')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/app/{appId}/promote', postfix: 'v1')]
	public function promotionPlan(string $appId, string $connection = ''): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->promotions === null) {
			return new DataResponse(['message' => 'Unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$plan = $this->promotions->plan($connection, $appId);
		if ($plan instanceof PromotionRefusal) {
			/** @psalm-suppress InvalidReturnStatement, ArgumentTypeCoercion Dynamic status passthrough — see the docblock. */
			return new DataResponse(['code' => $plan->code, 'message' => $plan->message], $plan->statusCode);
		}

		return new DataResponse($plan);
	}

	/**
	 * Promotes the version an earlier stage runs to this instance (password-confirmed)
	 *
	 * @param string $appId The app to promote
	 * @param string $connection The connection id of the earlier stage
	 * @param string $version The version the admin saw; refused when the earlier stage runs another one now
	 * @param bool $allowDowngrade Acknowledges that the promotion installs an older version
	 * @param bool $overrideMinDays Promotes inside the waiting period; written into the audit row
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT|Http::STATUS_UNPROCESSABLE_ENTITY|Http::STATUS_INTERNAL_SERVER_ERROR|Http::STATUS_BAD_GATEWAY|Http::STATUS_SERVICE_UNAVAILABLE, array<string, mixed>, array{}>
	 *
	 * 200: Installed; the payload names the previous version for the rollback
	 * 400: The install request is not acceptable
	 * 403: Caller is not an administrator, or the source is not trusted
	 * 404: No such connection, or the version is not in the source
	 * 409: The waiting period is not met, the version moved, the digests differ, or a guard refused
	 * 422: The package differs from the recorded digest
	 * 500: The install failed
	 * 502: The connection could not be read
	 * 503: Promotion is not available on this instance
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-an-admin-promotes-the-exact-package-an-earlier-stage-runs
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-a-version-waits-a-set-number-of-days-on-the-earlier-stage
	 * @psalm-suppress InvalidReturnType
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/app/{appId}/promote')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/app/{appId}/promote', postfix: 'v1')]
	public function promote(string $appId, string $connection = '', string $version = '', bool $allowDowngrade = false, bool $overrideMinDays = false): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->promotions === null) {
			return new DataResponse(['message' => 'Unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$result = $this->promotions->promote($connection, $appId, $allowDowngrade, $overrideMinDays, $version === '' ? null : $version);

		/** @psalm-suppress InvalidReturnStatement, ArgumentTypeCoercion Dynamic status passthrough — see the docblock. */
		return new DataResponse($result['payload'], $result['statusCode']);
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
	 * @return DataResponse<Http::STATUS_CREATED, array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string, remote?: array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_CONFLICT, array{message: string}, array{}>
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
	 * @return DataResponse<Http::STATUS_OK, array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string, remote?: array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND, array{message: string}, array{}>
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
	 * @param Http::STATUS_OK|Http::STATUS_CREATED $status The status of a saved connection.
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_CREATED, array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string, remote?: array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	private function respond(InstanceConnection|string $saved, int $status): DataResponse {
		if (is_string($saved)) {
			return new DataResponse(['message' => $saved], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->withRemote($saved, $this->readNow($saved)), $status);
	}

	/**
	 * Read one connection's manifest now; a read that throws keeps its last manifest.
	 *
	 * @spec openspec/changes/install-multi-instance-promotion/specs/multi-instance/spec.md#requirement-the-instances-tab-shows-every-instances-versions-side-by-side
	 * @return array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}|null
	 */
	private function readNow(InstanceConnection $connection): ?array {
		if ($this->reader === null) {
			return null;
		}

		try {
			return $this->reader->fetch($connection);
		} catch (\Throwable) {
			$kept = $this->reader->cached($connection);
			$kept['error'] = RemoteInstanceReader::ERROR_UNREACHABLE;

			return $kept;
		}
	}

	/**
	 * A connection without its password, with the last read of its manifest when there is a reader.
	 *
	 * @param array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}|null $remote The cached or fresh read
	 * @return array{id: string, name: string, stage: string, order: int, baseUrl: string, username: string, addedBy: string, addedAt: string, remote?: array{manifest: ?array<string, mixed>, fetchedAt: ?string, error: ?string, checkedAt: ?string}}
	 */
	private function withRemote(InstanceConnection $connection, ?array $remote): array {
		$public = $connection->toPublicArray();

		return $remote === null ? $public : $public + ['remote' => $remote];
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
