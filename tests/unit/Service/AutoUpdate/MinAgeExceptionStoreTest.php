<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

use OCA\Versioniq\Service\AutoUpdate\MinAgeExceptionStore;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One minimum-age exception per app, kept as `{version, by, at}` (releases-minimum-age D5).
 *
 * @spec openspec/changes/releases-minimum-age/specs/auto-update-policies/spec.md
 */
#[CoversClass(MinAgeExceptionStore::class)]
final class MinAgeExceptionStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];

	private function store(): MinAgeExceptionStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$app . '/' . $key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$app . '/' . $key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$app . '/' . $key]);
		});

		return new MinAgeExceptionStore($appConfig);
	}

	public function testANewExceptionReplacesTheOldAndIsRemovedPerApp(): void {
		$store = $this->store();
		$store->set('deck', '1.3.0', 'admin', new \DateTimeImmutable('2026-11-14T12:00:00+00:00'));
		$store->set('deck', '1.4.0', 'alice', new \DateTimeImmutable('2026-11-15T08:00:00+00:00'));
		$store->set('mail', '5.0.0', 'admin', new \DateTimeImmutable('2026-11-14T12:00:00+00:00'));

		self::assertSame(['version' => '1.4.0', 'by' => 'alice', 'at' => '2026-11-15T08:00:00+00:00'], $store->get('deck'));
		self::assertArrayHasKey('versioniq/min_age_exception.deck', $this->config);

		$store->remove('deck');

		self::assertNull($store->get('deck'));
		self::assertSame('5.0.0', $store->get('mail')['version'] ?? null);
	}

	public function testAnUnreadableOrIncompleteValueReadsAsNone(): void {
		$store = $this->store();
		foreach (['not json', '{"by":"admin"}', '{"version":""}', '"1.3.0"'] as $raw) {
			$this->config['versioniq/min_age_exception.deck'] = $raw;
			self::assertNull($store->get('deck'), $raw);
		}
		self::assertNull($store->get('contacts'));
	}
}
