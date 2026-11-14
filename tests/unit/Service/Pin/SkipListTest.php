<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Pin;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Pin\SkipList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The versions of an app an admin skipped (pinning-ranges-and-skips D2).
 *
 * @spec openspec/changes/pinning-ranges-and-skips/specs/version-pinning/spec.md#requirement-an-admin-skips-one-version-and-keeps-later-ones
 */
final class SkipListTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	private AuditLogger&MockObject $audit;

	private function skipList(): SkipList {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->stored[$key]);
		});
		$this->audit = $this->createMock(AuditLogger::class);

		return new SkipList($config, $this->audit, new NullLogger());
	}

	public function testASkipIsStoredWithWhoWhenAndWhyAndAudited(): void {
		$list = $this->skipList();
		$this->audit->expects(self::once())->method('record')->with(
			self::callback(static fn ($subject): bool => $subject->operation === AuditLogger::OPERATION_SKIP_VERSION && $subject->appId === 'openregister' && $subject->actorUid === 'admin'),
			self::callback(static fn ($versions): bool => $versions->toVersion === '2.3.4'),
			AuditLogger::STATUS_SUCCESS,
			'breaks the import',
		);

		self::assertTrue($list->skip('openregister', '2.3.4', 'admin', '2026-11-14T09:00:00+00:00', ' breaks the import '));

		self::assertSame(
			[['version' => '2.3.4', 'skippedBy' => 'admin', 'skippedAt' => '2026-11-14T09:00:00+00:00', 'reason' => 'breaks the import']],
			$list->entries('openregister')
		);
		self::assertSame(['2.3.4'], $list->versions('openregister'));
		self::assertSame([], $list->versions('notes'));
	}

	public function testSkippingTwiceKeepsTheFirstEntryAndAuditsOnce(): void {
		$list = $this->skipList();
		$this->audit->expects(self::once())->method('record');

		self::assertTrue($list->skip('openregister', '2.3.4', 'admin', 'T1', 'first'));
		self::assertFalse($list->skip('openregister', '2.3.4', 'bob', 'T2', 'second'));

		self::assertSame('first', $list->entries('openregister')[0]['reason']);
		self::assertCount(1, $list->entries('openregister'));
	}

	public function testTheListKeepsTheNewestTwentySkips(): void {
		$list = $this->skipList();
		for ($i = 1; $i <= SkipList::MAX + 3; $i++) {
			$list->skip('openregister', '1.0.' . $i, 'admin', 'T', '');
		}

		$versions = $list->versions('openregister');
		self::assertCount(SkipList::MAX, $versions);
		self::assertSame('1.0.4', $versions[0]);
		self::assertSame('1.0.23', $versions[SkipList::MAX - 1]);
	}

	public function testUnskipRemovesTheVersionAuditsAndClearsAnEmptyList(): void {
		$list = $this->skipList();
		$list->skip('openregister', '2.3.4', 'admin', 'T', '');
		$this->audit->expects(self::once())->method('record')->with(
			self::callback(static fn ($subject): bool => $subject->operation === AuditLogger::OPERATION_UNSKIP_VERSION),
			self::callback(static fn ($versions): bool => $versions->toVersion === '2.3.4'),
			AuditLogger::STATUS_SUCCESS,
		);

		self::assertTrue($list->unskip('openregister', '2.3.4', 'admin'));
		self::assertFalse($list->unskip('openregister', '2.3.4', 'admin'));
		self::assertSame([], $list->entries('openregister'));
		self::assertArrayNotHasKey('skip.openregister', $this->stored);
	}

	public function testAMalformedListReadsAsEmpty(): void {
		$list = $this->skipList();
		$this->stored['skip.openregister'] = '{not json';
		self::assertSame([], $list->entries('openregister'));

		$this->stored['skip.openregister'] = '[{"version":""},{"nope":1},{"version":"2.0.0","reason":3}]';
		self::assertSame([['version' => '2.0.0', 'skippedBy' => '', 'skippedAt' => '', 'reason' => '']], $list->entries('openregister'));
	}

	public function testAReasonIsCappedAtFiveHundredCharacters(): void {
		$list = $this->skipList();
		$list->skip('openregister', '2.3.4', 'admin', 'T', str_repeat('x', 600));

		self::assertSame(SkipList::MAX_REASON, mb_strlen($list->entries('openregister')[0]['reason']));
	}
}
