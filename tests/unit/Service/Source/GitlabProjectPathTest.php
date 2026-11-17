<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\Versioniq\Service\Source\GitlabProjectPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitlabProjectPathTest extends TestCase {
	public function testANestedPathOfSafeSegmentsPasses(): void {
		GitlabProjectPath::assertSafe('group/sub-group/my_project.v2');
		GitlabProjectPath::assertSafe(implode('/', array_fill(0, GitlabProjectPath::MAX_SEGMENTS, 'g')));

		$this->addToAssertionCount(1);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function unsafePaths(): array {
		return [
			'one segment' => ['project', 'must have 2 to 20 segments'],
			'too many segments' => [implode('/', array_fill(0, GitlabProjectPath::MAX_SEGMENTS + 1, 'g')), 'must have 2 to 20 segments'],
			'dot segment' => ['group/./project', 'invalid segment'],
			'dot-dot segment' => ['group/../project', 'invalid segment'],
			'empty segment' => ['group//project', 'invalid segment'],
			'space in a segment' => ['group/my project', 'invalid segment'],
		];
	}

	#[DataProvider('unsafePaths')]
	public function testAnUnsafePathIsRefused(string $path, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		GitlabProjectPath::assertSafe($path);
	}
}
