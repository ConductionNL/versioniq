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
use OCA\Versioniq\Http\InstanceSettingsFields;
use OCA\Versioniq\Http\RequestFieldsFrom;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and sets the instance settings that used to be occ-only (#438 item
 * 7): audit retention, artifact cache size, App Store and GitHub base URLs
 * and the advisory feed URL.
 *
 * Admin-only twice over, like ForgeController: no NoAdminRequired attribute,
 * and the explicit isAdmin() guard the unit tests pin. A change is audited,
 * because pointing the App Store or GitHub at another host changes where
 * installs come from.
 *
 * @psalm-suppress UnusedClass
 */
class SettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private InstanceSettings $settings,
		private AuditLogger $auditLogger,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Returns the instance settings with their defaults
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, bool|int|string|null>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The settings, each override ('' when unset) next to its default
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/instance-settings')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/instance-settings', postfix: 'v1')]
	public function instanceSettings(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->settings->read());
	}

	/**
	 * Updates the instance settings (password-confirmed); an omitted field is left alone
	 *
	 * @return DataResponse<Http::STATUS_OK, array<string, bool|int|string|null>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The settings after the change
	 * 400: A value is out of range or not an acceptable URL; nothing was changed
	 * 403: Caller is not an administrator
	 *
	 * @psalm-suppress MoreSpecificReturnType The empty 400 for a mistyped field stays out of the documented contract.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/instance-settings')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/instance-settings', postfix: 'v1')]
	#[RequestFieldsFrom(InstanceSettingsFields::class)]
	public function updateInstanceSettings(): Response {
		// The fields are declared in InstanceSettingsFields; one that is not
		// text gets the empty 400 the Dispatcher gives a mistyped parameter.
		$sent = InstanceSettingsFields::fromRequest($this->request);
		if ($sent === null) {
			/** @psalm-suppress LessSpecificReturnStatement Kept out of the documented contract, as before. */
			return new Response(Http::STATUS_BAD_REQUEST);
		}
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$fields = $sent->sent();

		try {
			$applied = $this->settings->update($fields);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$after = $this->settings->read();
		if ($applied !== []) {
			$this->auditLogger->record(
				new AuditSubject($this->userSession->getUser()?->getUID() ?? '', 'versioniq', AuditLogger::OPERATION_SETTINGS),
				new AuditedVersions(null, null, null),
				AuditLogger::STATUS_SUCCESS,
				'Settings changed: ' . implode(', ', array_map(
					// An on/off setting reads as on or off, not as 1 or an empty string.
					static fn (string $field): string => $field . '=' . (is_bool($after[$field]) ? ($after[$field] ? 'on' : 'off') : (string)$after[$field]),
					$applied,
				)),
			);
		}

		return new DataResponse($after);
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
