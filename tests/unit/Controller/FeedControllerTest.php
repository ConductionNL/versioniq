<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\FeedController;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseFeed;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-new-releases-are-available-as-an-rss-feed-for-admins
 */
final class FeedControllerTest extends TestCase {
	private function controller(bool $admin, bool $signedIn = true): FeedController {
		$log = [
			['appId' => 'hermiq', 'version' => '1.5.0', 'sourceId' => 'github:ConductionNL/hermiq', 'compatible' => true, 'releasedAt' => null, 'detectedAt' => 1_790_000_000],
			['appId' => 'deck', 'version' => '2.0.0', 'sourceId' => 'appstore', 'compatible' => true, 'releasedAt' => 1_700_000_000, 'detectedAt' => 1_789_000_000],
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode($log));
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://example.test/settings/admin/versioniq');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		return new FeedController(
			'versioniq',
			$this->createMock(IRequest::class),
			new ReleaseFeed(new ReleaseEventLog($config), $url),
			new ApiCaller($session, $groups, $this->createMock(ITimeFactory::class)),
		);
	}

	public function testANonAdminOrAnonymousCallerGets403AndNoRelease(): void {
		foreach ([$this->controller(false), $this->controller(false, false)] as $controller) {
			$response = $controller->releases();

			self::assertSame(403, $response->getStatus());
			self::assertNotInstanceOf(DataDisplayResponse::class, $response);
		}
	}

	public function testAnAdminGetsRss2WithOneItemPerRelease(): void {
		$response = $this->controller(true)->releases();

		self::assertInstanceOf(DataDisplayResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		// getHeaders() reaches the server container; read the headers the response was given.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame('application/rss+xml; charset=utf-8', $headers['Content-Type']);

		$rss = simplexml_load_string($response->getData());
		self::assertNotFalse($rss);
		self::assertSame('2.0', (string)$rss['version']);
		self::assertCount(2, $rss->channel->item);
		self::assertSame('hermiq 1.5.0', (string)$rss->channel->item[0]->title);
		self::assertSame('https://example.test/settings/admin/versioniq', (string)$rss->channel->item[0]->link);
		self::assertSame('github:ConductionNL/hermiq', (string)$rss->channel->item[0]->category);
		// No release date: the detection time; with one: the release date.
		self::assertSame(gmdate(DATE_RSS, 1_790_000_000), (string)$rss->channel->item[0]->pubDate);
		self::assertSame(gmdate(DATE_RSS, 1_700_000_000), (string)$rss->channel->item[1]->pubDate);
	}

	public function testTheRouteIsAFrontpageGetThatRequiresAnAdmin(): void {
		$method = new \ReflectionMethod(FeedController::class, 'releases');
		$route = $method->getAttributes(FrontpageRoute::class)[0]->getArguments();

		self::assertSame(['verb' => 'GET', 'url' => '/feed/releases.rss'], $route);
		self::assertSame([], $method->getAttributes(NoAdminRequired::class));
	}
}
