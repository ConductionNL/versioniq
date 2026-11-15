<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Exception;
use OCA\Versioniq\Service\Installer\FailureClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Golden over FailureClassifier::categoryFor(): the category of every message
 * phrase it knows (alone, in a different case, and two phrases together) and
 * of an opaque message at every stage, compared with
 * fixtures/failure-category.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Service\Installer\FailureClassifier
 * @uses \OCA\Versioniq\Service\Installer\AppAutoloader
 */
final class FailureCategoryGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/failure-category.golden.json';

	private const PHRASES = ['not compatible', 'app id', 'appid', 'version does not match', 'declares version', 'version is missing', 'sha-256', 'checksum', 'download', 'extract', 'permission', 'not writable', 'cannot write', 'backup existing app folder', 'create app destination folder', 'resolve app install folder'];
	private const STAGES = [null, 'requested-install', 'downloaded', 'checksum', 'archive-extracted', 'info-validated', 'filesystem-updated', 'finalize', 'other'];

	public function testCategories(): void {
		$classifier = $this->getMockBuilder(FailureClassifier::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		$actual = [];
		foreach (self::PHRASES as $phrase) {
			$actual['phrase ' . $phrase] = $classifier->categoryFor(new Exception('Could not ' . $phrase . ' here'), 'finalize');
			$actual['upper ' . $phrase] = $classifier->categoryFor(new Exception(strtoupper($phrase)));
			foreach (self::PHRASES as $second) {
				$actual['pair ' . $phrase . ' + ' . $second] = $classifier->categoryFor(new Exception($phrase . ' then ' . $second));
			}
		}
		foreach (self::STAGES as $stage) {
			$actual['stage ' . ($stage ?? 'none')] = $classifier->categoryFor(new Exception('opaque'), $stage);
		}
		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertSame((string)file_get_contents(self::FIXTURE), $json);
	}
}
