<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Snapshot;

use OCA\Versioniq\Service\Snapshot\RunMode;
use PHPUnit\Framework\TestCase;

/**
 * How a rollback run goes: the flags, and the listener that hears each outcome.
 *
 * @covers \OCA\Versioniq\Service\Snapshot\RunMode
 * @spec openspec/changes/install-bulk-rollback/specs/cli-commands/spec.md
 */
final class RunModeTest extends TestCase {
	public function testTheDefaultIsARealRunThatStopsAndLeavesPins(): void {
		$mode = new RunMode();

		$this->assertFalse($mode->dryRun);
		$this->assertFalse($mode->movePins);
		$this->assertFalse($mode->continue);
	}

	public function testTheListenerHearsEveryOutcomeInOrder(): void {
		$heard = [];
		$mode = new RunMode(true, true, true, function (array $outcome) use (&$heard): void {
			$heard[] = $outcome['appId'];
		});
		$mode->notify(['appId' => 'mail']);
		$mode->notify(['appId' => 'deck']);

		$this->assertTrue($mode->dryRun);
		$this->assertTrue($mode->movePins);
		$this->assertTrue($mode->continue);
		$this->assertSame(['mail', 'deck'], $heard);
	}

	public function testWithoutAListenerNotifyDoesNothing(): void {
		$mode = new RunMode(false, true);
		$mode->notify(['appId' => 'mail']);

		$this->assertTrue($mode->movePins);
		$this->assertFalse($mode->continue);
	}
}
