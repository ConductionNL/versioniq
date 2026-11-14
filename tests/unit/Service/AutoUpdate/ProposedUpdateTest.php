<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use InvalidArgumentException;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProposedUpdate::class)]
final class ProposedUpdateTest extends TestCase {
	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function steps(): array {
		return [
			'patch' => ['2.3.0', '2.3.4', 'patch'],
			'minor' => ['2.3.0', '2.4.0', 'minor'],
			'major' => ['2.3.0', '3.0.0', 'major'],
			'not semver' => ['2.3.0', '2.4.0-beta', 'other'],
		];
	}

	#[DataProvider('steps')]
	public function testKindOfNamesTheStep(string $from, string $to, string $kind): void {
		$this->assertSame($kind, ProposedUpdate::kindOf($from, $to));
	}

	public function testReleaseNotesAreCutTo2000Characters(): void {
		$cut = ProposedUpdate::excerpt(str_repeat('a', 2500));

		$this->assertSame(2000, mb_strlen($cut));
		$this->assertStringEndsWith('…', $cut);
		$this->assertSame('short', ProposedUpdate::excerpt('  short  '));
	}

	public function testAnApprovedProposalRoundTripsWithItsDecision(): void {
		$proposal = (new ProposedUpdate('openregister', '2.3.0', '2.3.4', 'patch', ProposedUpdate::STATUS_AWAITING_APPROVAL, '2026-07-01T00:00:00+00:00'))
			->with(ProposedUpdate::STATUS_APPROVED, '2026-07-02T01:00:00+00:00', null, ['by' => 'alice', 'at' => '2026-07-01T12:00:00+00:00']);

		$array = $proposal->toArray();

		$this->assertSame('alice', $array['approvedBy']);
		$this->assertSame('2026-07-01T12:00:00+00:00', $array['approvedAt']);
		$this->assertNull($array['declinedBy']);
		$this->assertSame($array, ProposedUpdate::fromArray($array)->toArray());
	}

	public function testADeclinedProposalCarriesItsReason(): void {
		$proposal = (new ProposedUpdate('deck', '1.9.0', '1.9.3', 'patch', ProposedUpdate::STATUS_PLANNED, '2026-07-01T00:00:00+00:00'))
			->with(ProposedUpdate::STATUS_DECLINED, null, null, ['by' => 'alice', 'at' => '2026-07-01T12:00:00+00:00', 'reason' => 'breaks our board import']);

		$array = $proposal->toArray();

		$this->assertSame('breaks our board import', $array['declineReason']);
		$this->assertNull($array['approvedBy']);
		$this->assertSame('breaks our board import', ProposedUpdate::fromArray($array)->toArray()['declineReason']);
	}

	public function testAnUnknownStatusIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		new ProposedUpdate('deck', '1.9.0', '1.9.3', 'patch', 'maybe', '2026-07-01T00:00:00+00:00');
	}

	public function testAPayloadWithoutATargetIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Proposed update payload is missing toVersion');
		ProposedUpdate::fromArray(['appId' => 'deck', 'fromVersion' => '1.9.0', 'kind' => 'patch', 'status' => 'planned', 'proposedAt' => 'x']);
	}
}
