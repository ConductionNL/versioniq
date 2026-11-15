<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCA\Versioniq\Service\Source\UntrustedSourceException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for how TrustedSourceList stores the allowlist (trimmed, blank
 * entries dropped, as JSON under one key), how it reads back a stored value
 * that is not a JSON list, and the binding-level allowlist check.
 *
 * @covers \OCA\Versioniq\Service\Source\TrustedSourceList
 * @uses \OCA\Versioniq\Service\Source\SourceBinding
 * @uses \OCA\Versioniq\Service\Source\UntrustedSourceException
 */
final class TrustedSourceListStorageTest extends TestCase {
	/** @var array<string, string> */
	private array $store = [];

	private function list(): TrustedSourceList {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$app . '/' . $key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$app . '/' . $key] = $value;

				return true;
			},
		);

		return new TrustedSourceList($config);
	}

	public function testStoredPatternsAreTrimmedAndBlanksDropped(): void {
		$list = $this->list();

		$list->setPatterns([' github:ConductionNL/* ', '', '   ', 'forgejo:team/app']);

		$this->assertSame(['versioniq/trusted_sources' => '["github:ConductionNL\/*","forgejo:team\/app"]'], $this->store);
		$this->assertSame(['github:ConductionNL/*', 'forgejo:team/app'], $list->getPatterns());
	}

	public function testStoringNothingFallsBackToTheDefaultOnRead(): void {
		$list = $this->list();

		$list->setPatterns(['  ']);

		$this->assertSame(['versioniq/trusted_sources' => '[]'], $this->store);
		$this->assertSame(['github:ConductionNL/*'], $list->getPatterns());
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function notAList(): array {
		return [
			'string' => ['"github:evil/*"'],
			'number' => ['42'],
			'null' => ['null'],
		];
	}

	/**
	 * @dataProvider notAList
	 */
	public function testAStoredValueThatIsNotAListReadsAsTheDefault(string $stored): void {
		$this->store['versioniq/trusted_sources'] = $stored;

		$this->assertSame(['github:ConductionNL/*'], $this->list()->getPatterns());
	}

	public function testABindingOnAnAllowlistedRepositoryPasses(): void {
		$this->store['versioniq/trusted_sources'] = '["github:ConductionNL/*"]';
		$list = $this->list();

		$list->assertBindingAllowed(SourceBinding::github('ConductionNL', 'openregister'));
		$list->assertBindingAllowed(SourceBinding::appStore());

		$this->addToAssertionCount(1);
	}

	public function testABindingOutsideTheAllowlistIsRefused(): void {
		$this->store['versioniq/trusted_sources'] = '["github:ConductionNL/*"]';

		$this->expectException(UntrustedSourceException::class);
		$this->list()->assertBindingAllowed(SourceBinding::github('evil', 'app'));
	}
}
