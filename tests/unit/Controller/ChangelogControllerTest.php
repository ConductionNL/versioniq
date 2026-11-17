<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ChangelogController;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Release\NotesTranslationRequest;
use OCA\Versioniq\Service\Release\NotesTranslator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Translating release notes is for admins only, and the translator's status
 * and payload are the response (releases-notes-reach 2.2).
 *
 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
 */
#[CoversClass(ChangelogController::class)]
final class ChangelogControllerTest extends TestCase {
	private NotesTranslator&MockObject $translator;

	protected function setUp(): void {
		parent::setUp();
		$this->translator = $this->createMock(NotesTranslator::class);
	}

	private function controller(bool $admin, bool $signedIn = true): ChangelogController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		return new ChangelogController('versioniq', $this->createMock(IRequest::class), $this->translator, new ApiCaller($session, $groups, $this->createMock(ITimeFactory::class)));
	}

	public function testAnAdminGetsTheTranslatorsAnswer(): void {
		$this->translator->expects($this->once())->method('translate')
			->with(new NotesTranslationRequest('deck', '2.0.0', 'nl', null, 'alice'))
			->willReturn(['statusCode' => 200, 'payload' => ['translation' => 'Vertaald', 'machineTranslation' => true]]);

		$response = $this->controller(true)->translate(' deck ', ' 2.0.0 ', 'nl');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['translation' => 'Vertaald', 'machineTranslation' => true], $response->getData());
	}

	public function testTheSourceOverrideIsPassedTrimmedAndABlankOneIsDropped(): void {
		$this->translator->expects($this->exactly(2))->method('translate')
			->willReturnCallback(static function (NotesTranslationRequest $request): array {
				return ['statusCode' => 200, 'payload' => ['source' => $request->source]];
			});
		$controller = $this->controller(true);

		self::assertSame(['source' => 'github:acme/deck'], $controller->translate('deck', '2.0.0', 'nl', ' github:acme/deck ')->getData());
		self::assertSame(['source' => null], $controller->translate('deck', '2.0.0', 'nl', '  ')->getData());
	}

	public function testATranslatorRefusalKeepsItsStatus(): void {
		$this->translator->method('translate')->willReturn(['statusCode' => 409, 'payload' => ['message' => 'No provider']]);

		$response = $this->controller(true)->translate('deck', '2.0.0', 'nl');

		self::assertSame(409, $response->getStatus());
		self::assertSame(['message' => 'No provider'], $response->getData());
	}

	public function testANonAdminOrAnonymousCallerIsRefusedBeforeAnyTranslation(): void {
		$this->translator->expects($this->never())->method('translate');

		foreach ([$this->controller(false), $this->controller(true, false)] as $controller) {
			$response = $controller->translate('deck', '2.0.0', 'nl');
			self::assertSame(403, $response->getStatus());
			self::assertSame(['message' => 'Forbidden'], $response->getData());
		}
	}
}
