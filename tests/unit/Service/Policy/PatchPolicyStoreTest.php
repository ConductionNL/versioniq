<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Policy;

require_once __DIR__ . '/PatchPolicyTest.php';

use OCA\Versioniq\Service\Policy\PatchPolicy;
use OCA\Versioniq\Service\Policy\PatchPolicyStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stores the patch policy in app config key `patch_policy` (autoupdate-security-first D3).
 *
 * @covers \OCA\Versioniq\Service\Policy\PatchPolicyStore
 * @uses \OCA\Versioniq\Service\Policy\PatchPolicy
 * @uses \OCA\Versioniq\Service\Advisory\AdvisorySeverity
 * @spec openspec/changes/autoupdate-security-first/specs/auto-update-policies/spec.md#requirement-an-admin-records-a-patch-policy-and-security-fixes-go-first
 */
final class PatchPolicyStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	private function store(?LoggerInterface $logger = null): PatchPolicyStore {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored[$app . '/' . $key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;
				return true;
			},
		);

		return new PatchPolicyStore($config, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testNothingStoredReadsAsNoPolicy(): void {
		$store = $this->store();

		$this->assertNull($store->read());
		$this->assertFalse($store->effective()->isRecorded());
		$this->assertSame('scheduled', $store->effective()->securityHandling('critical'));
	}

	public function testTheExampleRoundTrips(): void {
		$store = $this->store();
		$policy = PatchPolicy::fromArray(PatchPolicyTest::example())->recordedBy('alice', '2026-09-27T10:00:00+00:00');

		$store->write($policy);

		$this->assertArrayHasKey('versioniq/patch_policy', $this->stored);
		$this->assertSame($policy->toArray(), $store->read()?->toArray());
	}

	public function testMalformedJsonIsLoggedAndReadAsNoPolicy(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('PatchPolicyStore: malformed patch policy, treating as none', $this->arrayHasKey('message'));
		$store = $this->store($logger);
		$this->stored['versioniq/patch_policy'] = '{not json';

		$this->assertNull($store->read());
	}

	public function testAnInvalidStoredValueIsReadAsNoPolicy(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$store = $this->store($logger);

		$this->stored['versioniq/patch_policy'] = '"text"';
		$this->assertNull($store->read());

		$this->stored['versioniq/patch_policy'] = '{"regular":{"major":"sometimes"}}';
		$this->assertFalse($store->effective()->isRecorded());
	}
}
