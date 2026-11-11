<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryResultStoreTest extends TestCase {
	private function store(IAppConfig $config, ?LoggerInterface $logger = null): AdvisoryResultStore {
		return new AdvisoryResultStore($config, $logger ?? $this->createMock(LoggerInterface::class));
	}

	/**
	 * "Never checked" is a real state on a fresh install, and must not be
	 * reported as "checked, nothing found" — those differ in whether the admin
	 * has any assurance at all.
	 */
	public function testReportsNeverCheckedWhenNothingIsStored(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$this->assertSame(
			['advisories' => [], 'checkedAt' => null],
			$this->store($config)->read(),
		);
	}

	public function testReadsBackWhatWasStored(): void {
		$snapshot = ['notes' => ['appId' => 'notes', 'state' => 'none']];

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode($snapshot, JSON_THROW_ON_ERROR));
		$config->method('getValueInt')->willReturn(1_700_000_000);

		$this->assertSame(
			['advisories' => $snapshot, 'checkedAt' => 1_700_000_000],
			$this->store($config)->read(),
		);
	}

	/**
	 * A zero timestamp means the key was absent. Collapsing it to null keeps
	 * "never checked" as ONE value rather than two the caller must both know.
	 */
	public function testTreatsAZeroTimestampAsNeverChecked(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{"notes":{"state":"none"}}');
		$config->method('getValueInt')->willReturn(0);

		$this->assertNull($this->store($config)->read()['checkedAt']);
	}

	/**
	 * Corrupt storage must degrade to "never checked" rather than throwing
	 * into a page-load path.
	 */
	public function testReportsNeverCheckedWhenTheStoredValueIsNotJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not json');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertSame(
			['advisories' => [], 'checkedAt' => null],
			$this->store($config, $logger)->read(),
		);
	}

	public function testSavesTheSnapshotAndTheTime(): void {
		$snapshot = ['notes' => ['appId' => 'notes', 'state' => 'none']];

		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('setValueString')
			->with($this->anything(), 'advisory.results', json_encode($snapshot, JSON_THROW_ON_ERROR));
		$config->expects($this->once())
			->method('setValueInt')
			->with($this->anything(), 'advisory.results.checkedAt', 1_700_000_000);

		$this->store($config)->save($snapshot, 1_700_000_000);
	}

	/**
	 * A snapshot that cannot be encoded must LEAVE THE PREVIOUS ONE ALONE. A
	 * stale answer whose age is displayed beats no answer at all, and clearing
	 * the store here would silently turn a working badge set into "never
	 * checked".
	 */
	public function testKeepsThePreviousSnapshotWhenEncodingFails(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('setValueString');
		$config->expects($this->never())->method('setValueInt');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		// NAN is not encodable by json_encode, so this reaches the catch.
		$this->store($config, $logger)->save(['broken' => ['value' => NAN]], 1_700_000_000);
	}
}
