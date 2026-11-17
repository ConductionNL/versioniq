<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Lifecycle;

use InvalidArgumentException;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Lifecycle\SuccessorList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/inventory-lifecycle-signals/specs/lifecycle-signals/spec.md#requirement-an-admin-replaces-an-app-with-its-successor-in-one-action
 */
#[CoversClass(SuccessorList::class)]
final class SuccessorListTest extends TestCase {
	/** @var list<array{0: string, 1: ?string}> */
	private array $listed = [];

	/**
	 * @param array<string, array<string, mixed>> $listings Version listing per successor app id.
	 * @param array<string, string> $config
	 */
	private function successors(array $listings = [], array $config = []): SuccessorList {
		[$appConfig] = InMemoryAppConfig::for($this, $config);
		$installer = $this->createMock(InstallerService::class);
		$installer->method('getAppVersions')->willReturnCallback(function (string $appId, ?string $source) use ($listings): array {
			$this->listed[] = [$appId, $source];

			return $listings[$appId] ?? ['availableVersions' => [], 'hasError' => true, 'error' => 'not listed'];
		});

		return new SuccessorList($appConfig, $installer);
	}

	public function testTheShippedEntriesComeFirstAndCarryNoCustomFlag(): void {
		$entries = $this->successors(config: ['successors.custom' => '[{"from":"oldname","to":"newname","source":"appstore"}]'])->entries();

		$this->assertSame(['from' => 'app_versions', 'to' => 'versioniq', 'source' => 'github:ConductionNL/versioniq', 'custom' => false], $entries[0]);
		$this->assertSame(['from' => 'oldname', 'to' => 'newname', 'source' => 'appstore', 'custom' => true], $entries[array_key_last($entries)]);
	}

	public function testASuccessorWithACompatibleVersionIsOfferedWithItsNewestOne(): void {
		$list = $this->successors(
			['newname' => ['hasError' => false, 'availableVersions' => [
				['version' => '1.0.0', 'serverCompatible' => true],
				['version' => '1.2.0', 'serverCompatible' => true],
				['version' => '2.0.0', 'serverCompatible' => false],
				['version' => '1.5.0'],
			]]],
			['successors.custom' => '[{"from":"oldname","to":"newname","source":"appstore"}]'],
		);

		$this->assertSame(
			['from' => 'oldname', 'to' => 'newname', 'source' => 'appstore', 'custom' => true, 'version' => '1.2.0'],
			$list->offerFor('oldname')
		);
		$this->assertSame([['newname', 'appstore']], $this->listed);
	}

	public function testASuccessorWithoutACompatibleVersionIsNotOffered(): void {
		$config = ['successors.custom' => '[{"from":"oldname","to":"newname","source":"appstore"}]'];

		$this->assertNull($this->successors(['newname' => ['hasError' => false, 'availableVersions' => [['version' => '1.0.0', 'serverCompatible' => false]]]], $config)->offerFor('oldname'));
		$this->assertNull($this->successors(['newname' => ['hasError' => true, 'error' => 'offline', 'availableVersions' => []]], $config)->offerFor('oldname'));
		$this->assertNull($this->successors([], $config)->offerFor('unrelated'));
	}

	public function testAnAdminAddsAndRemovesAnEntry(): void {
		$list = $this->successors();

		$this->assertSame(['from' => 'oldmaps', 'to' => 'maps', 'source' => 'github:nextcloud/maps', 'custom' => true], $list->add(' oldmaps ', 'maps', 'github:nextcloud/maps'));
		$this->assertContains(['from' => 'oldmaps', 'to' => 'maps', 'source' => 'github:nextcloud/maps', 'custom' => true], $list->entries());
		$this->assertTrue($list->remove('oldmaps', 'maps'));
		$this->assertFalse($list->remove('oldmaps', 'maps'));
		$this->assertNotContains('oldmaps', array_column($list->entries(), 'from'));
	}

	public function testAShippedEntryCannotBeRemoved(): void {
		$list = $this->successors();

		$this->assertFalse($list->remove('app_versions', 'versioniq'));
		$this->assertSame('app_versions', $list->entries()[0]['from']);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function malformed(): array {
		return [
			'not an app id' => ['Old App', 'newname', 'appstore'],
			'its own successor' => ['same', 'same', 'appstore'],
			'an unknown source' => ['oldname', 'newname', 'https://example.org/app'],
			'already listed' => ['app_versions', 'versioniq', 'appstore'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
	public function testAMalformedOrDuplicateEntryIsRefused(string $from, string $to, string $source): void {
		$list = $this->successors();

		$this->expectException(InvalidArgumentException::class);
		$list->add($from, $to, $source);
	}

	public function testMalformedStoredEntriesAreSkipped(): void {
		$entries = $this->successors(config: ['successors.custom' => '[{"from":"a b","to":"x","source":"appstore"},{"from":"ok"},"text",{"from":"good","to":"better","source":"appstore"}]'])->entries();

		$this->assertSame(['app_versions', 'good'], array_column($entries, 'from'));
		$this->assertSame([], array_slice($this->successors(config: ['successors.custom' => 'not json'])->entries(), 1));
	}
}
