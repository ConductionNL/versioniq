<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Release;

use InvalidArgumentException;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Release\KnownIssueStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Admin known issues per version (releases-version-facts D4).
 *
 * @spec openspec/changes/releases-version-facts/specs/changelog-visibility/spec.md#requirement-known-issues-of-a-version-are-shown-before-and-after-installing-it
 */
final class KnownIssueStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $values = [];

	/** @var list<array{AuditSubject, AuditedVersions, string, ?string}> */
	private array $audited = [];

	private int $clock = 0;

	private function store(): KnownIssueStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->values[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->values[$key] = $value;
			return true;
		});
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $versions, $status, $message];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime('@' . (1_800_000_000 + $this->clock++)));

		return new KnownIssueStore($config, $audit, $time);
	}

	public function testAnAddedIssueIsStoredShownAndAudited(): void {
		$store = $this->store();
		$target = ['appId' => 'openregister', 'version' => '2.4.1', 'actor' => 'alice'];

		$issues = $store->add($target, ['text' => ' Export to CSV times out on large registers ', 'workaround' => 'Export per schema', 'link' => 'https://example.org/issue/1']);

		$this->assertSame('Export to CSV times out on large registers', $issues[0]['text']);
		$this->assertSame('Export per schema', $issues[0]['workaround']);
		$this->assertSame('https://example.org/issue/1', $issues[0]['link']);
		$this->assertSame('alice', $issues[0]['addedBy']);
		$this->assertSame(['2.4.1' => $issues], $store->forApp('openregister'));
		$this->assertSame([], $store->forApp('deck'));

		[$subject, $versions, $status, $message] = $this->audited[0];
		$this->assertSame(['alice', 'openregister', 'settings'], [$subject->actorUid, $subject->appId, $subject->operation]);
		$this->assertSame('2.4.1', $versions->toVersion);
		$this->assertSame('success', $status);
		$this->assertSame('Known issue added: Export to CSV times out on large registers', $message);
	}

	public function testAnHttpLinkAMissingTextAndAnOverlongWorkaroundAreRefused(): void {
		$store = $this->store();
		$target = ['appId' => 'deck', 'version' => '1.0.0', 'actor' => 'alice'];

		foreach ([
			['text' => 'Broken', 'link' => 'http://example.org/x'],
			['text' => 'Broken', 'link' => 'not a url'],
			['text' => '   '],
			['text' => 'Broken', 'workaround' => str_repeat('w', 501)],
		] as $issue) {
			try {
				$store->add($target, $issue);
				$this->fail('expected a refusal');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		$this->assertSame([], $this->values);
		$this->assertSame([], $this->audited);
	}

	public function testAtMostTwentyPerAppTheOldestDroppedFirst(): void {
		$store = $this->store();
		for ($i = 1; $i <= 21; $i++) {
			$store->add(['appId' => 'deck', 'version' => $i <= 2 ? '1.0.0' : '2.0.0', 'actor' => 'alice'], ['text' => 'Issue ' . $i]);
		}

		$notes = $store->forApp('deck');
		$this->assertSame(['Issue 2'], array_column($notes['1.0.0'], 'text'));
		$this->assertCount(19, $notes['2.0.0']);
		$this->assertSame(20, count($notes['1.0.0']) + count($notes['2.0.0']));
	}

	public function testRemovingByPositionAndAnUnknownPositionRefused(): void {
		$store = $this->store();
		$target = ['appId' => 'deck', 'version' => '1.0.0', 'actor' => 'bob'];
		$store->add($target, ['text' => 'First']);
		$store->add($target, ['text' => 'Second']);

		$left = $store->remove($target, 0);

		$this->assertSame(['Second'], array_column($left, 'text'));
		$this->assertSame('Known issue removed: First', $this->audited[2][3]);
		$store->remove($target, 0);
		$this->assertSame([], $store->forApp('deck'));

		$this->expectException(InvalidArgumentException::class);
		$store->remove($target, 3);
	}
}
