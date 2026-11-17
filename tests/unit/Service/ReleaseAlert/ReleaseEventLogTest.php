<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\ReleaseAlert;

require_once __DIR__ . '/ReleaseAlertTestCase.php';

use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;

/**
 * @spec openspec/changes/releases-new-release-alerts/specs/release-alerts/spec.md#requirement-a-new-release-is-detected-after-each-availability-sweep-and-logged
 */
final class ReleaseEventLogTest extends ReleaseAlertTestCase {
	public function testNewestFirst(): void {
		$log = new ReleaseEventLog($this->appConfig());

		$log->append([self::event('hermiq', '1.5.0'), self::event('deck', '2.0.0')]);
		$log->append([self::event('hermiq', '1.6.0')]);

		self::assertSame(['hermiq 1.6.0', 'deck 2.0.0', 'hermiq 1.5.0'], array_map(
			static fn (array $e): string => $e['appId'] . ' ' . $e['version'],
			$log->all(),
		));
	}

	public function testKeepsAtMost200Entries(): void {
		$log = new ReleaseEventLog($this->appConfig());
		$batch = [];
		for ($i = 1; $i <= 205; $i++) {
			$batch[] = self::event('app' . $i, '1.0.' . $i);
		}

		$log->append($batch);

		$all = $log->all();
		self::assertCount(ReleaseEventLog::MAX_ENTRIES, $all);
		self::assertSame('app205', $all[0]['appId']);
		self::assertSame('app6', $all[199]['appId']);
	}

	public function testNothingToAppendWritesNothingAndAnUnreadableLogReadsEmpty(): void {
		$log = new ReleaseEventLog($this->appConfig());
		$log->append([]);
		self::assertArrayNotHasKey(ReleaseEventLog::KEY, $this->stored);

		$this->stored[ReleaseEventLog::KEY] = '{not json';
		self::assertSame([], $log->all());

		$this->stored[ReleaseEventLog::KEY] = '[{"appId": "x"}, 3, {"appId": "deck", "version": "2.0.0"}]';
		self::assertSame([['appId' => 'deck', 'version' => '2.0.0']], $log->all());
	}
}
