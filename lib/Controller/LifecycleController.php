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
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Lifecycle\AppReplacer;
use OCA\Versioniq\Service\Lifecycle\ServerEolFeed;
use OCA\Versioniq\Service\Lifecycle\SuccessorList;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The life cycle of what is installed: the running server's end-of-life date,
 * the successor list, and replacing an app with its successor
 * (inventory-lifecycle-signals, design D3 to D5).
 *
 * Admin-only twice over: the routes carry no NoAdminRequired attribute, so
 * Nextcloud's middleware already refuses non-admins, and isAdmin() below is
 * the explicit guard the unit tests pin.
 *
 * @psalm-suppress UnusedClass
 */
class LifecycleController extends OCSController {
	use ChecksAdmin;

	public function __construct(
		string $appName,
		IRequest $request,
		private ServerEolFeed $eolFeed,
		private ServerVersionProvider $serverVersion,
		private SuccessorList $successors,
		private AppReplacer $replacer,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the running server major and its end-of-life date
	 *
	 * @return DataResponse<Http::STATUS_OK, array{major: int, eolDate: ?string}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The major and its date; the date is null when the feed is off, unreachable or does not list the major
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
	 */
	#[ApiRoute(verb: 'GET', url: '/api/lifecycle/server')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/lifecycle/server', postfix: 'v1')]
	public function server(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		$major = (int)$this->serverVersion->current();

		return new DataResponse(['major' => $major, 'eolDate' => $major > 0 ? $this->eolFeed->eolDate($major) : null]);
	}

	/**
	 * Lists the successor entries, shipped and the admin's own
	 *
	 * @return DataResponse<Http::STATUS_OK, array{successors: list<array{from: string, to: string, source: string, custom: bool}>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The entries
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 */
	#[ApiRoute(verb: 'GET', url: '/api/successors')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/successors', postfix: 'v1')]
	public function successors(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['successors' => $this->successors->entries()]);
	}

	/**
	 * Adds a successor entry of the admin's own
	 *
	 * @param string $from The app that was renamed or replaced
	 * @param string $to The app that succeeds it
	 * @param string $source The successor's source id: appstore or <forge>:<owner>/<repo>
	 *
	 * @return DataResponse<Http::STATUS_OK, array{successor: array{from: string, to: string, source: string, custom: bool}}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The entry was added
	 * 400: The entry is malformed or already listed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 */
	#[ApiRoute(verb: 'POST', url: '/api/successors')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/successors', postfix: 'v1')]
	public function addSuccessor(string $from = '', string $to = '', string $source = ''): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		try {
			return new DataResponse(['successor' => $this->successors->add($from, $to, $source)]);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Removes a successor entry of the admin's own; a shipped entry stays
	 *
	 * @param string $from The app that was renamed or replaced
	 * @param string $to The app that succeeds it
	 *
	 * @return DataResponse<Http::STATUS_OK, array{removed: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Whether an entry was removed
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 */
	#[ApiRoute(verb: 'DELETE', url: '/api/successors/{from}/{to}')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/successors/{from}/{to}', postfix: 'v1')]
	public function removeSuccessor(string $from, string $to): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['removed' => $this->successors->remove($from, $to)]);
	}

	/**
	 * Returns the successor offered for an app, with the newest version of it this server can run
	 *
	 * @param string $appId The app
	 *
	 * @return DataResponse<Http::STATUS_OK, array{offer: ?array{from: string, to: string, source: string, custom: bool, version: string}}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The offer, null when no listed successor has a version this server can run
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 */
	#[ApiRoute(verb: 'GET', url: '/api/app/{appId}/successor')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/app/{appId}/successor', postfix: 'v1')]
	public function successorOffer(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['offer' => $this->successors->offerFor($appId)]);
	}

	/**
	 * Replaces an app with its successor: installs the successor, then disables the app
	 *
	 * @param string $appId The app to replace
	 * @param string $to The successor the admin confirmed
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, mixed>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The outcome; `replaced` says whether the switch happened, `statusCode` is the failing step's status, and a failed install leaves the app enabled
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/app/{appId}/replace')]
	#[ApiRoute(verb: 'POST', url: '/api/v1/app/{appId}/replace', postfix: 'v1')]
	public function replace(string $appId, string $to = ''): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}
		$outcome = $this->replacer->replace($user->getUID(), $appId, $to);

		return new DataResponse(['statusCode' => $outcome['statusCode']] + $outcome['payload']);
	}
}
