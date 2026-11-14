<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Installer\ForceOverride;
use OCA\Versioniq\Service\Installer\ForceOverrideStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Versioniq's use of `app_install_overwrite`: add only what is missing,
 * remove only its own entry, and keep a record per override it set
 * (install-force-incompatible D2).
 */
final class ForceOverrideStoreTest extends TestCase {
	/** @var list<mixed> The system value as the config holds it. */
	private mixed $list = [];

	/** @var list<array{0: string, 1: mixed}> */
	private array $writes = [];

	/** @var array<string, string> */
	private array $appValues = [];

	private ?\Closure $beforeRead = null;

	private function store(?AuditLogger $audit = null): ForceOverrideStore {
		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValue')->willReturnCallback(function (string $key, mixed $default): mixed {
			if ($this->beforeRead !== null) {
				($this->beforeRead)();
			}

			return $key === ForceOverrideStore::SYSTEM_KEY ? $this->list : $default;
		});
		$system->method('setSystemValue')->willReturnCallback(function (string $key, mixed $value): void {
			$this->writes[] = [$key, $value];
			$this->list = $value;
		});
		$system->method('deleteSystemValue')->willReturnCallback(function (string $key): void {
			$this->writes[] = [$key, null];
			$this->list = [];
		});

		$app = $this->createMock(IAppConfig::class);
		$app->method('setValueString')->willReturnCallback(function (string $appId, string $key, string $value): bool {
			$this->appValues[$key] = $value;

			return true;
		});
		$app->method('getValueString')->willReturnCallback(fn (string $appId, string $key, string $default = ''): string => $this->appValues[$key] ?? $default);
		$app->method('getAllValues')->willReturnCallback(fn (string $appId, string $prefix = ''): array => array_filter(
			$this->appValues,
			static fn (string $key): bool => str_starts_with($key, $prefix),
			ARRAY_FILTER_USE_KEY
		));
		$app->method('deleteKey')->willReturnCallback(function (string $appId, string $key): void {
			unset($this->appValues[$key]);
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T08:00:00+00:00'));

		return new ForceOverrideStore($system, $app, $audit ?? $this->createMock(AuditLogger::class), $time, $this->createMock(LoggerInterface::class));
	}

	public function testAnEntryAlreadyPresentIsNotAddedAndNotVersioniqs(): void {
		$this->list = ['calendar', 'hermiq'];

		$this->assertFalse($this->store()->apply('hermiq'));
		$this->assertSame([], $this->writes);
		$this->assertTrue($this->store()->isOverridden('hermiq'));
	}

	public function testAMissingEntryIsAddedAndRemovedAgain(): void {
		$this->list = ['calendar'];
		$store = $this->store();

		$this->assertTrue($store->apply('hermiq'));
		$this->assertSame(['calendar', 'hermiq'], $this->list);

		$store->remove('hermiq');
		$this->assertSame(['calendar'], $this->list);
	}

	public function testRemovingTheLastEntryDeletesTheKey(): void {
		$this->list = [];
		$store = $this->store();
		$store->apply('hermiq');

		$store->remove('hermiq');

		$this->assertSame([ForceOverrideStore::SYSTEM_KEY, null], $this->writes[1]);
		$this->assertFalse($store->isOverridden('hermiq'));
	}

	public function testAnEntryAnotherWriterAddedInBetweenIsKept(): void {
		$this->list = ['calendar'];
		$store = $this->store();
		$store->apply('hermiq');
		// Someone else edits the list after Versioniq's write and before its removal.
		$this->beforeRead = function (): void {
			if (!in_array('deck', $this->list, true)) {
				$this->list[] = 'deck';
			}
		};

		$store->remove('hermiq');

		$this->assertSame(['calendar', 'deck'], $this->list);
	}

	public function testRemovingAnAbsentEntryWritesNothing(): void {
		$this->list = ['calendar'];

		$this->store()->remove('hermiq');

		$this->assertSame([], $this->writes);
	}

	public function testANonListSystemValueReadsAsEmpty(): void {
		$this->list = 'hermiq';
		$store = $this->store();

		$this->assertFalse($store->isOverridden('hermiq'));
		$this->assertTrue($store->apply('hermiq'));
		$this->assertSame(['hermiq'], $this->list);
	}

	public function testARecordIsStoredReadBackAndAudited(): void {
		$audit = $this->createMock(AuditLogger::class);
		$audit->expects($this->once())->method('record')->with(
			$this->callback(static fn (AuditSubject $subject): bool => $subject->actorUid === 'alice' && $subject->appId === 'hermiq' && $subject->operation === 'force_incompatible'),
			$this->callback(static fn (AuditedVersions $versions): bool => $versions->fromVersion === null && $versions->toVersion === '1.4.0'),
			AuditLogger::STATUS_SUCCESS,
			'Forced past its declared maximum on Nextcloud 33.',
		);
		$store = $this->store($audit);

		$store->record('hermiq', '1.4.0', 33, 'alice', true);

		$override = $store->get('hermiq');
		$this->assertInstanceOf(ForceOverride::class, $override);
		$this->assertSame(
			['version' => '1.4.0', 'serverMajor' => 33, 'setBy' => 'alice', 'setAt' => '2026-11-14T08:00:00+00:00', 'addedByVersioniq' => true],
			$override->toArray()
		);
		$this->assertSame(['hermiq'], array_keys($store->all()));

		$store->forget('hermiq');
		$this->assertNull($store->get('hermiq'));
		$this->assertSame([], $store->all());
	}

	public function testAMalformedRecordReadsAsNone(): void {
		$this->appValues['force_incompatible.hermiq'] = '{"version":"1.4.0","serverMajor":"33"}';

		$this->assertNull($this->store()->get('hermiq'));
	}
}
