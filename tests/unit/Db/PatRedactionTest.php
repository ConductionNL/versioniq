<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Db;

use OCA\Versioniq\Db\Pat;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Pat::toRedacted() — every public field is present, the
 * encrypted token and the warning ledger never are, and the stored scope
 * JSON is decoded only when it is a JSON array — and for an empty ledger.
 *
 * @covers \OCA\Versioniq\Db\Pat
 */
final class PatRedactionTest extends TestCase {
	private function pat(?string $scopes): Pat {
		$pat = Pat::fromRow([
			'id' => 12,
			'owner_uid' => 'alice',
			'label' => 'Team',
			'target_pattern' => 'ConductionNL/*',
			'kind' => Pat::KIND_FINE_GRAINED,
			'forge' => 'github',
			'encrypted_token' => 'ciphertext',
			'token_hint' => 'a1b2',
			'shared_with_admins' => true,
			'last_validated_scopes' => $scopes,
			'expires_at' => '2027-01-01T00:00:00+00:00',
			'last_used_at' => null,
			'created_at' => '2026-11-01T00:00:00+00:00',
			'warned_thresholds' => '["14d"]',
		]);

		return $pat;
	}

	public function testRedactedFormCarriesEveryPublicFieldAndDecodedScopes(): void {
		$this->assertSame([
			'id' => 12,
			'ownerUid' => 'alice',
			'label' => 'Team',
			'targetPattern' => 'ConductionNL/*',
			'kind' => 'fine-grained',
			'forge' => 'github',
			'tokenHint' => 'a1b2',
			'sharedWithAdmins' => true,
			'lastValidatedScopes' => ['repo', 'read:org'],
			'expiresAt' => '2027-01-01T00:00:00+00:00',
			'lastUsedAt' => null,
			'createdAt' => '2026-11-01T00:00:00+00:00',
		], $this->pat('["repo","read:org"]')->toRedacted());
	}

	public function testTheTokenAndTheLedgerAreNeverExposed(): void {
		$redacted = $this->pat(null)->toRedacted();

		$this->assertArrayNotHasKey('encryptedToken', $redacted);
		$this->assertArrayNotHasKey('warnedThresholds', $redacted);
		$this->assertNotContains('ciphertext', $redacted);
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function undecodableScopes(): array {
		return [
			'none' => [null],
			'empty' => [''],
			'malformed' => ['{not json'],
			'scalar' => ['"repo"'],
		];
	}

	/**
	 * @dataProvider undecodableScopes
	 */
	public function testScopesThatAreNotAJsonArrayAreReportedAsNull(?string $scopes): void {
		$this->assertNull($this->pat($scopes)->toRedacted()['lastValidatedScopes']);
	}

	public function testAnEmptyLedgerStringIsAnEmptyList(): void {
		$pat = new Pat();
		$pat->setWarnedThresholds('');

		$this->assertSame([], $pat->getWarnedThresholdsList());
		$pat->addWarnedThreshold('7d');
		$this->assertSame(['7d'], $pat->getWarnedThresholdsList());
	}
}
