<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/BuildsByParameterName.php';

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Tests\Unit\Support\BuildsByParameterName;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The PAT endpoints act for the session user only: a forged `userId`
 * parameter names nobody, and an admin cannot change or delete another
 * admin's token.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 */
final class ApiPatOwnershipTest extends TestCase {
	use BuildsByParameterName;

	/**
	 * @param array<string, mixed> $named
	 */
	private function controllerFor(string $uid, array $named): ApiController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === 'userId' ? 'alice' : $default);
		$request->method('getParams')->willReturn(['userId' => 'alice']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $candidate): bool => in_array($candidate, ['alice', 'bob'], true));

		return $this->buildByName(ApiController::class, $named + [
			'appName' => 'versioniq',
			'request' => $request,
			'userSession' => $session,
			'groupManager' => $groups,
			'serverVersion' => (new \ReflectionClass(\OCP\ServerVersion::class))->newInstanceWithoutConstructor(),
		]);
	}

	private function alicesPat(): Pat {
		$pat = new Pat();
		$pat->setId(9);
		$pat->setOwnerUid('alice');
		$pat->setLabel('alice token');

		return $pat;
	}

	public function testListingIsForTheSessionUserNotAForgedOne(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects(self::once())->method('findVisibleTo')->with('bob')->willReturn([]);

		$response = $this->controllerFor('bob', ['patMapper' => $mapper])->listPats();

		self::assertSame(200, $response->getStatus());
	}

	public function testAnotherAdminCannotUpdateTheToken(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findById')->willReturn($this->alicesPat());
		$manager = $this->createMock(PatManager::class);
		$manager->expects(self::never())->method('update');

		$response = $this->controllerFor('bob', ['patMapper' => $mapper, 'patManager' => $manager])->patchPat(9, 'mine now', '1');

		self::assertSame(403, $response->getStatus());
	}

	public function testAnotherAdminCannotDeleteTheToken(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findById')->willReturn($this->alicesPat());
		$manager = $this->createMock(PatManager::class);
		$manager->expects(self::never())->method('delete');

		$response = $this->controllerFor('bob', ['patMapper' => $mapper, 'patManager' => $manager])->deletePat(9);

		self::assertSame(403, $response->getStatus());
	}

	public function testTheOwnerCanDeleteTheToken(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findById')->willReturn($this->alicesPat());
		$manager = $this->createMock(PatManager::class);
		$manager->expects(self::once())->method('delete');

		$response = $this->controllerFor('alice', ['patMapper' => $mapper, 'patManager' => $manager])->deletePat(9);

		self::assertSame(200, $response->getStatus());
	}
}
