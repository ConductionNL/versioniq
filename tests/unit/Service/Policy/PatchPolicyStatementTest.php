<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

require_once __DIR__ . '/PatchPolicyTest.php';

use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\PatchPolicyStatement;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The patch policy as sentences (autoupdate-security-first D5).
 *
 * @covers \OCA\Versioniq\Service\Policy\PatchPolicyStatement
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicy
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class PatchPolicyStatementTest extends TestCase {
	private function statement(): PatchPolicyStatement {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters));
		$l->method('n')->willReturnCallback(static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural));
		$l->method('l')->willReturnCallback(static fn (string $type, mixed $data): string => $data instanceof \DateTimeInterface ? $data->format('j F Y') : '');

		return new PatchPolicyStatement($l);
	}

	public function testTheExampleReadsAsSentences(): void {
		$lines = $this->statement()->lines(PatchPolicy::fromArray(PatchPolicyTest::example())->recordedBy('alice', '2026-09-27T10:00:00+00:00'));

		$this->assertSame([
			'Critical security fixes: installed at the next update window. Deadline: 3 days.',
			'High security fixes: installed at the next update window. Deadline: 7 days.',
			'Medium security fixes: installed with the regular automatic updates. Deadline: 30 days.',
			'Low security fixes: installed with the regular automatic updates. Deadline: 90 days.',
			'Security fixes of unknown severity: installed by hand only.',
			'Patch updates: installed automatically, as each app\'s update policy allows.',
			'Minor updates: installed automatically, as each app\'s update policy allows.',
			'Major updates: installed by hand only.',
			'Security fixes first.',
			'Last changed by alice on 27 September 2026.',
		], $lines);
	}

	public function testOneDayIsSingular(): void {
		$lines = $this->statement()->lines(PatchPolicy::fromArray(['security' => ['critical' => ['deadlineDays' => 1, 'handling' => 'next_window']]])->recordedBy('bob', '2026-01-02T00:00:00+00:00'));

		$this->assertSame('Critical security fixes: installed at the next update window. Deadline: 1 day.', $lines[0]);
		$this->assertSame('Last changed by bob on 2 January 2026.', end($lines));
	}

	public function testNoPolicySaysSo(): void {
		$this->assertSame(
			['No patch policy is recorded yet. Every update follows the app\'s own update policy.'],
			$this->statement()->lines(PatchPolicy::none()),
		);
	}

	public function testAnUnreadableDateIsShownAsStored(): void {
		$lines = $this->statement()->lines(PatchPolicy::fromArray([])->recordedBy('alice', 'not a date'));

		$this->assertSame('Last changed by alice on not a date.', end($lines));
	}
}
