<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/advisories-triage/specs/security-advisory-correlation/spec.md#requirement-an-admin-dismisses-an-advisory-with-a-recorded-reason
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\Dismissal;
use OCA\Versioniq\Service\Advisory\TriageRefusal;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Advisory\Dismissal
 * @uses \OCA\Versioniq\Service\Advisory\TriageRefusal
 * @uses \OCA\Versioniq\Service\Advisory\AdvisoryTriageStore
 */
final class DismissalTest extends TestCase {
	public function testEveryListedReasonIsAcceptedWithoutAnEndDateExceptAPlannedFix(): void {
		foreach (['not_used', 'inaccurate', 'tolerable_risk', 'mitigated'] as $reason) {
			$dismissal = Dismissal::from(['reason' => $reason, 'comment' => '', 'until' => '']);
			$this->assertSame([$reason, '', null], [$dismissal->reason, $dismissal->comment, $dismissal->until]);
		}
	}

	public function testAPlannedFixKeepsItsEndDateAsIso8601(): void {
		$dismissal = Dismissal::from(['reason' => 'fix_planned', 'comment' => 'Next sprint', 'until' => '2026-12-01']);

		$this->assertSame('2026-12-01T00:00:00+00:00', $dismissal->until);
		$this->assertSame('Next sprint', $dismissal->comment);
	}

	/**
	 * @return array<string, array{0: array{reason: string, comment: string, until: string}, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'unknown reason' => [['reason' => 'nope', 'comment' => '', 'until' => ''], 'reason must be one of: not_used, inaccurate, tolerable_risk, mitigated, fix_planned.'],
			'planned fix without end date' => [['reason' => 'fix_planned', 'comment' => '', 'until' => ''], 'A planned fix needs an end date.'],
			'unreadable end date' => [['reason' => 'mitigated', 'comment' => '', 'until' => 'soon-ish'], 'until must be a date.'],
			'comment too long' => [['reason' => 'mitigated', 'comment' => str_repeat('é', 1001), 'until' => ''], 'comment must be at most 1000 characters.'],
		];
	}

	/**
	 * @dataProvider refusals
	 * @param array{reason: string, comment: string, until: string} $fields
	 */
	public function testAnUnacceptableFieldIsRefusedWith400(array $fields, string $message): void {
		try {
			Dismissal::from($fields);
			$this->fail('expected a refusal');
		} catch (TriageRefusal $refusal) {
			$this->assertSame([400, $message], [$refusal->status(), $refusal->getMessage()]);
		}
	}

	public function testACommentOfExactlyTheLimitInMultibyteCharactersIsKept(): void {
		$comment = str_repeat('é', 1000);

		$this->assertSame($comment, Dismissal::from(['reason' => 'mitigated', 'comment' => $comment, 'until' => ''])->comment);
	}
}
