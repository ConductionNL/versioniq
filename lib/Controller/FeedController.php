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

use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseFeed;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves the release log as an RSS feed for admins. A plain controller, not
 * OCS, because a feed reader cannot read the OCS envelope. There is no
 * NoAdminRequired, so the framework requires an admin, and the method checks
 * again like every other route (releases-new-release-alerts, design D4).
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
 *
 * @psalm-api
 */
class FeedController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ReleaseFeed $feed,
		private ApiCaller $caller,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
	 */
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/feed/releases.rss')]
	public function releases(): Response {
		$user = $this->caller->userSession->getUser();
		if ($user === null || !$this->caller->groupManager->isAdmin($user->getUID())) {
			return new Response(Http::STATUS_FORBIDDEN);
		}

		return new DataDisplayResponse($this->feed->render(), Http::STATUS_OK, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
	}
}
