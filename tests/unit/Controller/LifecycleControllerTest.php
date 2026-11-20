<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Versioniq\Controller\LifecycleController;
use OCA\Versioniq\Service\Advisory\ServerVersionProvider;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Lifecycle\AppReplacer;
use OCA\Versioniq\Service\Lifecycle\LifecycleServices;
use OCA\Versioniq\Service\Lifecycle\ServerEolFeed;
use OCA\Versioniq\Service\Lifecycle\SuccessorList;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-admins-are-warned-before-the-server-leaves-support
 */
#[CoversClass(LifecycleController::class)]
#[UsesClass(LifecycleServices::class)]
#[UsesClass(ApiCaller::class)]
#[UsesClass(AuditSubject::class)]
final class LifecycleControllerTest extends TestCase {
	private ServerEolFeed&MockObject $feed;
	private ServerVersionProvider&MockObject $version;
	private SuccessorList&MockObject $successors;
	private AppReplacer&MockObject $replacer;

	protected function setUp(): void {
		$this->feed = $this->createMock(ServerEolFeed::class);
		$this->version = $this->createMock(ServerVersionProvider::class);
		$this->successors = $this->createMock(SuccessorList::class);
		$this->replacer = $this->createMock(AppReplacer::class);
	}

	private function controller(bool $admin = true, bool $signedIn = true): LifecycleController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		return new LifecycleController(
			'versioniq',
			$this->createMock(IRequest::class),
			new LifecycleServices($this->feed, $this->version, $this->successors, $this->replacer),
			new ApiCaller($session, $groups, $this->createMock(ITimeFactory::class)),
		);
	}

	public function testANonAdminIsRefusedEverywhereAndNothingChanges(): void {
		$this->successors->expects($this->never())->method($this->anything());
		$this->replacer->expects($this->never())->method('replace');
		$this->feed->expects($this->never())->method('eolDate');

		foreach ([$this->controller(false), $this->controller(true, false)] as $controller) {
			$this->assertSame(403, $controller->server()->getStatus());
			$this->assertSame(403, $controller->successors()->getStatus());
			$this->assertSame(403, $controller->addSuccessor('a', 'b', 'appstore')->getStatus());
			$this->assertSame(403, $controller->removeSuccessor('a', 'b')->getStatus());
			$this->assertSame(403, $controller->successorOffer('a')->getStatus());
			$this->assertSame(403, $controller->replace('a', 'b')->getStatus());
		}
	}

	public function testTheServerSectionGetsTheRunningMajorAndItsDate(): void {
		$this->version->method('current')->willReturn('31');
		$this->feed->expects($this->once())->method('eolDate')->with(31)->willReturn('2026-06-30');

		$this->assertSame(['major' => 31, 'eolDate' => '2026-06-30'], $this->controller()->server()->getData());
	}

	public function testAnUnknownServerVersionHasNoDate(): void {
		$this->version->method('current')->willReturn('');
		$this->feed->expects($this->never())->method('eolDate');

		$this->assertSame(['major' => 0, 'eolDate' => null], $this->controller()->server()->getData());
	}

	public function testAMalformedEntryIsABadRequest(): void {
		$this->successors->method('add')->with('a', 'a', 'appstore')->willThrowException(new InvalidArgumentException('An app cannot be its own successor.'));

		$response = $this->controller()->addSuccessor('a', 'a', 'appstore');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'An app cannot be its own successor.'], $response->getData());
	}

	public function testTheListAddRemoveAndOfferReachTheSuccessorList(): void {
		$entry = ['from' => 'oldmaps', 'to' => 'maps', 'source' => 'appstore', 'custom' => true];
		$this->successors->method('entries')->willReturn([$entry]);
		$this->successors->method('add')->with('oldmaps', 'maps', 'appstore')->willReturn($entry);
		$this->successors->method('remove')->with('oldmaps', 'maps')->willReturn(true);
		$this->successors->method('offerFor')->with('oldmaps')->willReturn($entry + ['version' => '1.0.0']);
		$controller = $this->controller();

		$this->assertSame(['successors' => [$entry]], $controller->successors()->getData());
		$this->assertSame(['successor' => $entry], $controller->addSuccessor('oldmaps', 'maps', 'appstore')->getData());
		$this->assertSame(['removed' => true], $controller->removeSuccessor('oldmaps', 'maps')->getData());
		$this->assertSame(['offer' => $entry + ['version' => '1.0.0']], $controller->successorOffer('oldmaps')->getData());
	}

	public function testReplaceRunsAsTheSignedInAdminAndSaysWhichStepFailed(): void {
		$this->replacer->expects($this->once())->method('replace')
			->with($this->callback(static fn (AuditSubject $subject): bool => $subject->actorUid === 'alice' && $subject->appId === 'oldname' && $subject->operation === AppReplacer::OPERATION_REPLACE), 'newname')
			->willReturn(['statusCode' => 409, 'payload' => ['replaced' => false, 'message' => 'The successor could not be installed; the old app stays enabled.']]);

		$response = $this->controller()->replace('oldname', 'newname');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['statusCode' => 409, 'replaced' => false, 'message' => 'The successor could not be installed; the old app stays enabled.'], $response->getData());
	}

	public function testReplaceAsksForThePasswordAndAnswersOnBothPaths(): void {
		$method = new \ReflectionMethod(LifecycleController::class, 'replace');

		$this->assertCount(1, $method->getAttributes(PasswordConfirmationRequired::class));
		$urls = array_map(static fn (\ReflectionAttribute $route): mixed => $route->getArguments()['url'] ?? null, $method->getAttributes(ApiRoute::class));
		$this->assertSame(['/api/app/{appId}/replace', '/api/v1/app/{appId}/replace'], $urls);
	}
}
