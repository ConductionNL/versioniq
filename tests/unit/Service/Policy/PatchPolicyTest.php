<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

use InvalidArgumentException;
use OCA\Versioniq\Service\Policy\PatchPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The patch policy record (autoupdate-security-first D3).
 *
 * @covers \OCA\Versioniq\Service\Policy\PatchPolicy
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class PatchPolicyTest extends TestCase {
	/** @return array<string, mixed> The design's example policy. */
	public static function example(): array {
		return [
			'security' => [
				'critical' => ['deadlineDays' => 3, 'handling' => 'next_window'],
				'high' => ['deadlineDays' => 7, 'handling' => 'next_window'],
				'medium' => ['deadlineDays' => 30, 'handling' => 'scheduled'],
				'low' => ['deadlineDays' => 90, 'handling' => 'scheduled'],
				'unknown' => ['deadlineDays' => null, 'handling' => 'manual'],
			],
			'regular' => ['patch' => 'automatic', 'minor' => 'automatic', 'major' => 'manual'],
			'statement' => 'Security fixes first.',
		];
	}

	public function testNoPolicyIsTodaysBehaviour(): void {
		$none = PatchPolicy::none();

		$this->assertFalse($none->isRecorded());
		foreach (PatchPolicy::SEVERITIES as $severity) {
			$this->assertSame(['deadlineDays' => null, 'handling' => 'scheduled'], $none->security[$severity]);
		}
		$this->assertSame(['patch' => 'automatic', 'minor' => 'automatic', 'major' => 'automatic'], $none->regular);
		$this->assertSame([], $none->manualKinds());
		$this->assertSame('', $none->statement);
	}

	public function testTheExampleIsReadAsWritten(): void {
		$policy = PatchPolicy::fromArray(self::example(), 'alice', '2026-09-27T10:00:00+00:00');

		$this->assertTrue($policy->isRecorded());
		$this->assertSame('next_window', $policy->securityHandling('critical'));
		$this->assertSame('manual', $policy->securityHandling('unknown'));
		$this->assertSame(3, $policy->security['critical']['deadlineDays']);
		$this->assertSame('manual', $policy->regularHandling('major'));
		$this->assertSame(['major'], $policy->manualKinds());
		$this->assertSame(self::example() + ['setBy' => 'alice', 'setAt' => '2026-09-27T10:00:00+00:00'], $policy->toArray());
	}

	public function testAnUnknownSeverityReadsAsUnknown(): void {
		$policy = PatchPolicy::fromArray(self::example());

		$this->assertSame('manual', $policy->securityHandling('severe'));
	}

	public function testALeftOutSeverityKeepsTheNoPolicyReading(): void {
		$policy = PatchPolicy::fromArray(['security' => ['critical' => ['deadlineDays' => '3', 'handling' => 'next_window']]]);

		$this->assertSame(['deadlineDays' => 3, 'handling' => 'next_window'], $policy->security['critical']);
		$this->assertSame(['deadlineDays' => null, 'handling' => 'scheduled'], $policy->security['high']);
		$this->assertSame('automatic', $policy->regularHandling('major'));
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function invalid(): array {
		return [
			'deadline 0' => [['security' => ['critical' => ['deadlineDays' => 0, 'handling' => 'scheduled']]], 'security.critical.deadlineDays'],
			'deadline 366' => [['security' => ['high' => ['deadlineDays' => 366, 'handling' => 'scheduled']]], 'security.high.deadlineDays'],
			'deadline not a number' => [['security' => ['low' => ['deadlineDays' => '3d', 'handling' => 'scheduled']]], 'security.low.deadlineDays'],
			'unknown handling' => [['security' => ['low' => ['deadlineDays' => 3, 'handling' => 'soon']]], 'security.low.handling'],
			'unknown severity' => [['security' => ['severe' => ['handling' => 'manual']]], 'unknown severity: severe'],
			'severity not an object' => [['security' => ['low' => 'manual']], 'security.low must be an object'],
			'security not an object' => [['security' => 'manual'], 'security must be an object'],
			'unknown regular handling' => [['regular' => ['major' => 'sometimes']], 'regular.major'],
			'unknown kind' => [['regular' => ['nightly' => 'manual']], 'unknown kind: nightly'],
			'regular not an object' => [['regular' => 'manual'], 'regular must be an object'],
			'statement not text' => [['statement' => ['a']], 'statement must be text'],
			'statement too long' => [['statement' => str_repeat('a', 4001)], 'at most 4000 characters'],
		];
	}

	/**
	 * @dataProvider invalid
	 * @param array<string, mixed> $payload
	 */
	public function testAnInvalidFieldIsRefusedByName(array $payload, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		PatchPolicy::fromArray($payload);
	}

	public function testAStatementOf4000CharactersIsAccepted(): void {
		$this->assertSame(4000, mb_strlen(PatchPolicy::fromArray(['statement' => str_repeat('é', 4000)])->statement));
	}

	public function testTheHighestSeverityDecides(): void {
		$this->assertSame('critical', PatchPolicy::highestSeverity([['severity' => 'low'], ['severity' => 'critical'], ['severity' => 'high']]));
		$this->assertSame('medium', PatchPolicy::highestSeverity([['severity' => 'moderate'], ['severity' => 'low']]));
		$this->assertSame('unknown', PatchPolicy::highestSeverity([['severity' => 'bogus'], ['id' => 'x']]));
		$this->assertSame('unknown', PatchPolicy::highestSeverity([]));
	}

	public function testTheKindOfAStep(): void {
		$this->assertSame('patch', PatchPolicy::kindOf('1.9.2', '1.9.3'));
		$this->assertSame('minor', PatchPolicy::kindOf('1.9.2', '1.10.0'));
		$this->assertSame('major', PatchPolicy::kindOf('1.9.2', '2.0.0'));
		$this->assertSame('patch', PatchPolicy::kindOf('v1.9', '1.9.1'));
	}

	public function testTheChangedFieldsAreNamed(): void {
		$before = PatchPolicy::none();
		$after = PatchPolicy::fromArray(['security' => ['critical' => ['deadlineDays' => 3, 'handling' => 'next_window']], 'regular' => ['major' => 'manual'], 'statement' => 'x'], 'alice', 'now');

		$this->assertSame(['security.critical', 'regular.major', 'statement'], $after->changedFrom($before));
		$this->assertSame([], $after->changedFrom($after));
	}
}
