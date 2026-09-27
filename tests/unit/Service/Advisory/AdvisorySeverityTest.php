<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisorySeverity;
use PHPUnit\Framework\TestCase;

/**
 * Issue #443: one mapping from whatever a source sends to the severity values
 * AdvisorySourceInterface documents.
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
final class AdvisorySeverityTest extends TestCase {
	/**
	 * @dataProvider cases
	 */
	public function testNormalise(mixed $raw, string $expected): void {
		self::assertSame($expected, AdvisorySeverity::normalize($raw));
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public static function cases(): array {
		return [
			'missing' => [null, 'unknown'],
			'empty' => ['', 'unknown'],
			'not a string' => [7, 'unknown'],
			'unrecognised' => ['severe', 'unknown'],
			'explicit unknown' => ['unknown', 'unknown'],
			'low' => ['low', 'low'],
			'upper case' => ['HIGH', 'high'],
			'padded' => [' critical ', 'critical'],
			'github moderate' => ['moderate', 'medium'],
			'medium' => ['medium', 'medium'],
		];
	}
}
