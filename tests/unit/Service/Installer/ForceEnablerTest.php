<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use OCA\Versioniq\Service\Installer\ForceEnabler;
use OCA\Versioniq\Service\Installer\ForceOverrideStore;
use OCP\App\IAppManager;
use OCP\ServerVersion;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * "Enable anyway" on a disabled app only its declared maximum keeps off this
 * server (install-force-incompatible D3).
 */
final class ForceEnablerTest extends TestCase {
	/** @var list<string> */
	private array $calls = [];

	private IAppManager&MockObject $appManager;

	public static function serverVersion(string $version): ServerVersion {
		$server = (new \ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(ServerVersion::class, 'versionString'))->setValue($server, $version);
		(new \ReflectionProperty(ServerVersion::class, 'version'))->setValue($server, array_map('intval', explode('.', $version)));

		return $server;
	}

	/**
	 * @param array<string, string> $declared The info.xml nextcloud attributes.
	 */
	private function enabler(array $declared = ['min-version' => '30', 'max-version' => '32'], bool $added = true, bool $enableFails = false, bool $installed = true, bool $enabled = false, bool $shipped = false): ForceEnabler {
		$overrides = $this->createMock(ForceOverrideStore::class);
		$overrides->method('apply')->willReturnCallback(function (string $appId) use ($added): bool {
			$this->calls[] = 'apply ' . $appId;

			return $added;
		});
		$overrides->method('remove')->willReturnCallback(function (string $appId): void {
			$this->calls[] = 'remove ' . $appId;
		});
		$overrides->method('record')->willReturnCallback(function (string $appId, string $version, int $major, string $actor, bool $wasAdded): void {
			$this->calls[] = sprintf('record %s %s on %d by %s added=%s', $appId, $version, $major, $actor, var_export($wasAdded, true));
		});

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isInstalled')->willReturn($installed);
		$this->appManager->method('isShipped')->willReturn($shipped);
		$this->appManager->method('isEnabledForAnyone')->willReturn($enabled);
		$this->appManager->method('getAppInfo')->willReturn(['dependencies' => ['nextcloud' => ['@attributes' => $declared]]]);
		$this->appManager->method('getAppVersion')->willReturn('1.4.0');
		$this->appManager->method('enableApp')->willReturnCallback(function (string $appId) use ($enableFails): void {
			$this->calls[] = 'enable ' . $appId;
			if ($enableFails) {
				throw new \RuntimeException('Missing PHP extension sodium');
			}
		});

		return new ForceEnabler($overrides, $this->appManager, self::serverVersion('33.0.2'));
	}

	public function testAnAppKeptOutByItsMaximumIsEnabledAndRecorded(): void {
		$result = $this->enabler()->enable('hermiq', 'alice');

		$this->assertSame(['statusCode' => 200, 'payload' => ['appId' => 'hermiq', 'enabled' => true, 'forcedOn' => 33]], $result);
		$this->assertSame(['apply hermiq', 'enable hermiq', 'record hermiq 1.4.0 on 33 by alice added=true'], $this->calls);
	}

	public function testAFailedEnableRemovesTheEntryVersioniqAdded(): void {
		$result = $this->enabler(enableFails: true)->enable('hermiq', 'alice');

		$this->assertSame(409, $result['statusCode']);
		$this->assertSame('Nextcloud did not enable the app: Missing PHP extension sodium', $result['payload']['message']);
		$this->assertSame(['apply hermiq', 'enable hermiq', 'remove hermiq'], $this->calls);
	}

	public function testAFailedEnableKeepsAnEntrySomeoneElseSet(): void {
		$this->enabler(added: false, enableFails: true)->enable('hermiq', 'alice');

		$this->assertSame(['apply hermiq', 'enable hermiq'], $this->calls);
	}

	public function testAVersionThatNeedsANewerServerIsRefusedWithoutTouchingTheList(): void {
		$result = $this->enabler(declared: ['min-version' => '34'])->enable('hermiq', 'alice');

		$this->assertSame(409, $result['statusCode']);
		$this->assertStringContainsString('an override cannot help this one', $result['payload']['message']);
		$this->assertSame([], $this->calls);
	}

	public function testAnAppThatAlreadySupportsTheServerIsRefused(): void {
		$this->assertSame(409, $this->enabler(declared: ['min-version' => '30', 'max-version' => '33'])->enable('hermiq', 'alice')['statusCode']);
		$this->assertSame([], $this->calls);
	}

	public function testOtherRefusals(): void {
		$this->assertSame(404, $this->enabler(installed: false)->enable('hermiq', 'alice')['statusCode']);
		$this->assertSame(404, $this->enabler()->enable('Not An Id', 'alice')['statusCode']);
		$this->assertSame(403, $this->enabler(shipped: true)->enable('files', 'alice')['statusCode']);
		$this->assertSame(409, $this->enabler(enabled: true)->enable('hermiq', 'alice')['statusCode']);
		$this->assertSame([], $this->calls);
	}
}
