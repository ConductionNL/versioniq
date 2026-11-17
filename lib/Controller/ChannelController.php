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
use OCA\Versioniq\Service\Channel\ChannelAuditor;
use OCA\Versioniq\Service\Channel\ChannelStore;
use OCA\Versioniq\Service\Channel\ReleaseChannel;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The release channels: the instance default, an app's own channel and an
 * app's long-term support lines. Admin-only; every change is
 * password-confirmed and audited as `settings` with the old and new value.
 *
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
 */
class ChannelController extends OCSController {
	/** A Nextcloud app id; `default` is the key of the instance default. */
	private const APP_ID_PATTERN = '/^(?!default$)[a-z0-9_]{1,40}$/';

	public function __construct(
		string $appName,
		IRequest $request,
		private ChannelStore $store,
		private ChannelAuditor $auditor,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The channels on offer, the default, every app's own channel and every app's marked lines.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The channels
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	#[ApiRoute(verb: 'GET', url: '/api/channels')]
	#[ApiRoute(verb: 'GET', url: '/api/v1/channels', postfix: 'v1')]
	public function channels(): DataResponse {
		if (!$this->isAdmin()) {
			return self::forbidden();
		}

		return new DataResponse($this->state());
	}

	/**
	 * Sets the instance default channel.
	 *
	 * @param string $channel server, stable, beta or lts
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The default is set
	 * 400: The channel is unknown
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/channel')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/channel', postfix: 'v1')]
	public function setDefault(string $channel = ''): DataResponse {
		if (!$this->isAdmin()) {
			return self::forbidden();
		}

		$before = $this->store->defaultChannel();
		try {
			$this->store->setDefault($channel);
		} catch (InvalidArgumentException $error) {
			return self::badRequest($error);
		}
		$this->audit('versioniq', 'Default release channel changed from ' . $before . ' to ' . $channel);

		return new DataResponse($this->state());
	}

	/**
	 * Sets an app's own channel.
	 *
	 * @param string $appId The app
	 * @param string $channel server, stable, beta or lts
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The app's channel is set
	 * 400: The channel or the app id is unknown
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/app/{appId}/channel')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/app/{appId}/channel', postfix: 'v1')]
	public function setAppChannel(string $appId, string $channel = ''): DataResponse {
		return $this->appRefusal($appId) ?? $this->changeAppChannel($appId, $channel);
	}

	/**
	 * Sets an app's own channel once the caller may; audited.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	private function changeAppChannel(string $appId, string $channel): DataResponse {
		$before = $this->store->appChannel($appId) ?? 'default';
		try {
			$this->store->setAppChannel($appId, $channel);
		} catch (InvalidArgumentException $error) {
			return self::badRequest($error);
		}
		$this->audit($appId, 'Release channel changed from ' . $before . ' to ' . $channel);

		return new DataResponse($this->state());
	}

	/**
	 * Clears an app's own channel, so it follows the default again.
	 *
	 * @param string $appId The app
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The app follows the default
	 * 400: The app id is unknown
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/app/{appId}/channel')]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/app/{appId}/channel', postfix: 'v1')]
	public function clearAppChannel(string $appId): DataResponse {
		return $this->appRefusal($appId) ?? $this->dropAppChannel($appId);
	}

	/**
	 * Clears an app's own channel once the caller may; audited when it had one.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>
	 */
	private function dropAppChannel(string $appId): DataResponse {
		$before = $this->store->appChannel($appId);
		$this->store->clearAppChannel($appId);
		if ($before !== null) {
			$this->audit($appId, 'Release channel changed from ' . $before . ' to default');
		}

		return new DataResponse($this->state());
	}

	/**
	 * Sets an app's long-term support lines; an empty list clears them.
	 *
	 * @param string $appId The app
	 * @param list<string>|string $lines The lines, as a list or comma-separated
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: The lines are set
	 * 400: A line is not `major` or `major.minor`, or the app id is unknown
	 * 403: Caller is not an administrator
	 *
	 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-the-long-term-support-channel-offers-only-marked-release-lines
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/app/{appId}/lts-lines')]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/app/{appId}/lts-lines', postfix: 'v1')]
	public function setLtsLines(string $appId, array|string $lines = []): DataResponse {
		return $this->appRefusal($appId) ?? $this->changeLtsLines($appId, $lines);
	}

	/**
	 * Sets an app's lines once the caller may; audited.
	 *
	 * @param array<array-key, mixed>|string $lines The lines sent.
	 *
	 * @return DataResponse<Http::STATUS_OK, array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	private function changeLtsLines(string $appId, array|string $lines): DataResponse {
		$before = $this->store->ltsLines($appId);
		try {
			$this->store->setLtsLines($appId, self::lineList($lines));
		} catch (InvalidArgumentException $error) {
			return self::badRequest($error);
		}
		$after = $this->store->ltsLines($appId);
		$this->audit($appId, 'Long-term support lines changed from [' . implode(', ', $before) . '] to [' . implode(', ', $after) . ']');

		return new DataResponse($this->state());
	}

	/**
	 * What the channel endpoints answer with.
	 *
	 * @return array{channels: list<string>, default: string, apps: array<string, string>, ltsLines: array<string, list<string>>}
	 */
	private function state(): array {
		return [
			'channels' => ReleaseChannel::ALL,
			'default' => $this->store->defaultChannel(),
			'apps' => $this->store->appChannels(),
			'ltsLines' => $this->store->allLtsLines(),
		];
	}

	/**
	 * The lines sent, as strings; a comma-separated text is split.
	 *
	 * @param array<array-key, mixed>|string $lines The lines sent.
	 *
	 * @return list<string>
	 */
	private static function lineList(array|string $lines): array {
		if (is_string($lines)) {
			$lines = $lines === '' ? [] : explode(',', $lines);
		}

		return array_values(array_map(static fn (mixed $line): string => is_scalar($line) ? (string)$line : '', $lines));
	}

	/**
	 * Records one settings change in the audit trail.
	 */
	private function audit(string $appId, string $message): void {
		$this->auditor->changed($appId, $message);
	}

	/**
	 * @return DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 */
	private static function forbidden(): DataResponse {
		return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
	}

	/**
	 * @return DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>
	 */
	private static function badRequest(InvalidArgumentException $error): DataResponse {
		return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * The refusal of a change to an app's settings: 403 for a non-admin, 400 for a malformed app id; null when it may go ahead.
	 *
	 * @return DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN, array{message: string}, array{}>|null
	 */
	private function appRefusal(string $appId): ?DataResponse {
		if (!$this->isAdmin()) {
			return self::forbidden();
		}

		return preg_match(self::APP_ID_PATTERN, $appId) === 1 ? null : self::badRequest(new InvalidArgumentException('Unknown app id: ' . $appId));
	}

	private function isAdmin(): bool {
		$uid = $this->userSession->getUser()?->getUID();

		return $uid !== null && $this->groupManager->isAdmin($uid);
	}
}
