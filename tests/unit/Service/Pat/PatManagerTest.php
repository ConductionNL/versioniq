<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\NewPat;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\ValidationResult;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class PatManagerTest extends TestCase {
	public function testBuildHintShortToken(): void {
		$this->assertSame('****', PatManager::buildHint('abc'));
		$this->assertSame('********', PatManager::buildHint('eightchr'));
	}

	public function testBuildHintFullToken(): void {
		$token = 'ghp_abcdefghijklmnopqrstuvwxyz1234567890';

		$this->assertSame('ghp_...7890', PatManager::buildHint($token));
	}

	public function testCreateEncryptsTokenAndCapturesHint(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())
			->method('encrypt')
			->with('ghp_secrettoken1234567890abcdef1234567890')
			->willReturn('encrypted-blob');

		$manager = new PatManager($mapper, $crypto);

		$pat = $manager->create(
			(new NewPat('admin', ValidationResult::accepted(['repo'], [], null)))
				->describedAs('My PAT', 'ConductionNL/*')
				->ofKind(Pat::KIND_CLASSIC),
			'ghp_secrettoken1234567890abcdef1234567890',
		);

		$this->assertSame('encrypted-blob', $pat->getEncryptedToken());
		$this->assertSame('ghp_...7890', $pat->getTokenHint());
		$this->assertSame('admin', $pat->getOwnerUid());
		$this->assertSame('ConductionNL/*', $pat->getTargetPattern());
		$this->assertSame('github', $pat->getForge());
		$this->assertFalse($pat->getSharedWithAdmins());

		$validated = json_decode($pat->getLastValidatedScopes() ?? '{}', true);
		$this->assertIsArray($validated);
		$this->assertSame(['repo'], $validated['scopes']);
	}

	public function testUseTokenDecryptsOnlyInsideCallback(): void {
		$pat = new Pat();
		$pat->setEncryptedToken('encrypted-blob');

		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())
			->method('decrypt')
			->with('encrypted-blob')
			->willReturn('plaintext-token');

		$manager = new PatManager($mapper, $crypto);

		$captured = null;
		$result = $manager->useToken($pat, function (string $plaintext) use (&$captured): string {
			$captured = $plaintext;

			return 'callback-result';
		});

		$this->assertSame('plaintext-token', $captured);
		$this->assertSame('callback-result', $result);
		$this->assertNotNull($pat->getLastUsedAt());
	}

	public function testRefreshValidationPersistsScopesAndExpiry(): void {
		$pat = new Pat();
		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$crypto = $this->createMock(ICrypto::class);
		$manager = new PatManager($mapper, $crypto);

		$validation = \OCA\Versioniq\Service\Pat\ValidationResult::accepted(
			['repo'],
			[],
			'2026-08-15 12:00:00'
		);

		$updated = $manager->refreshValidation($pat, $validation);

		$this->assertSame('2026-08-15 12:00:00', $updated->getExpiresAt());
		$validated = json_decode($updated->getLastValidatedScopes() ?? '{}', true);
		$this->assertIsArray($validated);
		$this->assertSame(['repo'], $validated['scopes']);
	}

	public function testRefreshValidationClearsLedgerWhenExpiryChanges(): void {
		$pat = Pat::fromRow([
			'id' => 1,
			'owner_uid' => 'alice',
			'label' => 'conduction-bot',
			'target_pattern' => 'ConductionNL/*',
			'kind' => Pat::KIND_CLASSIC,
			'forge' => 'github',
			'encrypted_token' => 'x',
			'token_hint' => 'x',
			'shared_with_admins' => false,
			'expires_at' => '2026-08-01 00:00:00',
			'created_at' => '2026-01-01 00:00:00',
			'warned_thresholds' => '["14d","3d"]',
		]);
		$this->assertTrue($pat->hasWarnedThreshold('14d'));

		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$manager = new PatManager($mapper, $this->createMock(ICrypto::class));

		$validation = \OCA\Versioniq\Service\Pat\ValidationResult::accepted(
			['repo'],
			[],
			'2026-09-01 00:00:00'
		);

		$updated = $manager->refreshValidation($pat, $validation);

		$this->assertSame([], $updated->getWarnedThresholdsList());
	}

	public function testUpdateClearsLedgerOnlyWhenExpiryFieldChanged(): void {
		$pat = Pat::fromRow([
			'id' => 1,
			'owner_uid' => 'alice',
			'label' => 'conduction-bot',
			'target_pattern' => 'ConductionNL/*',
			'kind' => Pat::KIND_CLASSIC,
			'forge' => 'github',
			'encrypted_token' => 'x',
			'token_hint' => 'x',
			'shared_with_admins' => false,
			'expires_at' => '2026-08-01 00:00:00',
			'created_at' => '2026-01-01 00:00:00',
			'warned_thresholds' => '["14d"]',
		]);

		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$manager = new PatManager($mapper, $this->createMock(ICrypto::class));

		// Only label changes — the ledger must survive.
		$pat->setLabel('renamed');
		$updated = $manager->update($pat);

		$this->assertSame(['14d'], $updated->getWarnedThresholdsList());
	}
}
