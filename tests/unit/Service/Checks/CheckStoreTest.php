<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Checks;

use InvalidArgumentException;
use OCA\Versioniq\Service\Checks\CheckStore;
use OCA\Versioniq\Service\Checks\UpdateCheck;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verdict checks are stored by an admin, at most ten, https only, the token
 * encrypted and never read back; command checks come from config.php only.
 *
 * @covers \OCA\Versioniq\Service\Checks\CheckStore
 * @covers \OCA\Versioniq\Service\Checks\UpdateCheck
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
final class CheckStoreTest extends TestCase {
	/** @var array<string, string> */
	private array $appValues = [];

	/** @var array<string, mixed> */
	private array $systemValues = [];

	/** @var list<string> */
	private array $warnings = [];

	private function store(): CheckStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->appValues[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->appValues[$key] = $value;

			return true;
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(fn (string $key, mixed $default = ''): mixed => $this->systemValues[$key] ?? $default);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'encrypted:' . base64_encode(strrev($plain)));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		return new CheckStore($appConfig, $config, $crypto, $logger);
	}

	/**
	 * @return array{name: string, url: string}
	 */
	private static function verdict(string $name = 'Staging tests'): array {
		return ['name' => $name, 'url' => 'https://ci.example.org/verdict/{appId}/{version}?from={fromVersion}'];
	}

	public function testStoresUpToTenAndRefusesTheEleventh(): void {
		$store = $this->store();
		$ten = array_map(static fn (int $i): array => self::verdict('Check ' . $i), range(1, 10));

		self::assertCount(10, $store->saveVerdictChecks($ten));
		self::assertCount(10, $store->verdictChecks());

		$before = $this->appValues[CheckStore::KEY];
		try {
			$store->saveVerdictChecks([...$ten, self::verdict('Check 11')]);
			self::fail('an eleventh check was accepted');
		} catch (InvalidArgumentException $error) {
			self::assertSame('At most 10 verdict checks can be set.', $error->getMessage());
		}
		self::assertSame($before, $this->appValues[CheckStore::KEY], 'a refused list changed the stored checks');
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refusedAddresses(): array {
		return [
			'http' => ['http://ci.example.org/verdict/{appId}'],
			'no scheme' => ['ci.example.org/verdict'],
			'ftp' => ['ftp://ci.example.org/verdict'],
			'credentials in the address' => ['https://user:pass@ci.example.org/verdict'],
			'no host' => ['https:///verdict'],
			'a line break' => ["https://ci.example.org/verdict\r\nX-Injected: 1"],
		];
	}

	/**
	 * @dataProvider refusedAddresses
	 */
	public function testAnAddressThatIsNotHttpsIsRefused(string $url): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must be an https address');

		try {
			$this->store()->saveVerdictChecks([['name' => 'Plain', 'url' => $url]]);
		} finally {
			self::assertArrayNotHasKey(CheckStore::KEY, $this->appValues);
		}
	}

	public function testReadNeverReturnsTheTokenAndStoresItEncrypted(): void {
		$store = $this->store();
		$store->saveVerdictChecks([self::verdict() + ['token' => 'ghp_s3cretvalue', 'apps' => ['openregister']]]);

		$read = $store->read();
		self::assertSame(['id', 'name', 'url', 'apps', 'hasToken'], array_keys($read['verdict'][0]));
		self::assertTrue($read['verdict'][0]['hasToken']);
		self::assertSame(['openregister'], $read['verdict'][0]['apps']);
		self::assertStringNotContainsString('s3cret', (string)json_encode($read));
		self::assertStringNotContainsString('s3cret', $this->appValues[CheckStore::KEY]);
		self::assertSame('encrypted:' . base64_encode(strrev('ghp_s3cretvalue')), $store->verdictChecks()[0]->encryptedToken);
	}

	public function testAnEditKeepsTheTokenUnlessANewOneOrAClearIsSent(): void {
		$store = $this->store();
		$id = $store->saveVerdictChecks([self::verdict() + ['token' => 'first-token']])[0]['id'];
		$encrypted = $store->verdictChecks()[0]->encryptedToken;

		$store->saveVerdictChecks([['id' => $id, 'name' => 'Renamed', 'url' => 'https://ci.example.org/v2/{appId}']]);
		self::assertSame($encrypted, $store->verdictChecks()[0]->encryptedToken);
		self::assertSame('Renamed', $store->verdictChecks()[0]->name);
		self::assertSame($id, $store->verdictChecks()[0]->id);

		$store->saveVerdictChecks([['id' => $id, 'clearToken' => true] + self::verdict()]);
		self::assertFalse($store->verdictChecks()[0]->hasToken());

		// An unknown id is a new check: it never inherits another check's token.
		$store->saveVerdictChecks([self::verdict() + ['token' => 'second-token']]);
		$store->saveVerdictChecks([['id' => 'not-a-stored-id'] + self::verdict()]);
		self::assertFalse($store->verdictChecks()[0]->hasToken());
		self::assertNotSame('not-a-stored-id', $store->verdictChecks()[0]->id);
	}

	public function testANameAndValidAppIdsAreRequired(): void {
		$store = $this->store();
		foreach ([[['url' => 'https://ci.example.org']], [['name' => ' ', 'url' => 'https://ci.example.org']], [self::verdict() + ['apps' => ['../etc']]], ['not a check'], 'not a list'] as $input) {
			try {
				$store->saveVerdictChecks($input);
				self::fail('accepted ' . json_encode($input));
			} catch (InvalidArgumentException) {
			}
		}
		self::assertSame(['a', 'b'], $store->saveVerdictChecks([self::verdict() + ['apps' => 'a, b a']])[0]['apps']);
	}

	public function testCommandChecksAreReadFromConfigPhp(): void {
		$this->systemValues[CheckStore::SYSTEM_KEY] = [
			['name' => 'Lint', 'command' => ['/opt/checks/lint.sh', '{path}'], 'timeout' => 30, 'apps' => ['openregister']],
			['name' => 'Smoke', 'command' => ['/opt/checks/smoke']],
			['name' => 'Long', 'command' => ['/opt/checks/long'], 'timeout' => 99999],
		];

		$checks = $this->store()->commandChecks();

		self::assertSame(['Lint', 'Smoke', 'Long'], array_map(static fn (UpdateCheck $check): string => $check->name, $checks));
		self::assertSame(UpdateCheck::KIND_COMMAND, $checks[0]->kind);
		self::assertSame(['/opt/checks/lint.sh', '{path}'], $checks[0]->command);
		self::assertSame(30, $checks[0]->timeout);
		self::assertTrue($checks[0]->appliesTo('openregister'));
		self::assertFalse($checks[0]->appliesTo('openconnector'));
		self::assertSame(UpdateCheck::DEFAULT_COMMAND_TIMEOUT, $checks[1]->timeout);
		self::assertTrue($checks[1]->appliesTo('anything'));
		self::assertSame(UpdateCheck::MAX_COMMAND_TIMEOUT, $checks[2]->timeout);
		self::assertCount(1, $this->warnings, 'the clamped timeout is logged');
	}

	/**
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function malformedSystemValues(): array {
		return [
			'a string' => ['/opt/checks/lint.sh', 1],
			'a map' => [['name' => 'Lint', 'command' => ['/bin/true']], 1],
			'a command as one text' => [[['name' => 'Lint', 'command' => '/opt/checks/lint.sh {path}']], 1],
			'no name' => [[['command' => ['/bin/true']]], 1],
			'an argument that is no text' => [[['name' => 'Lint', 'command' => ['/bin/true', 5]]], 1],
			'an empty program' => [[['name' => 'Lint', 'command' => ['', 'x']]], 1],
			'a timeout as text' => [[['name' => 'Lint', 'command' => ['/bin/true'], 'timeout' => '30']], 1],
			'a zero timeout' => [[['name' => 'Lint', 'command' => ['/bin/true'], 'timeout' => 0]], 1],
			'a bad app id' => [[['name' => 'Lint', 'command' => ['/bin/true'], 'apps' => ['Bad App']]], 1],
			'one bad among good' => [[['name' => 'Good', 'command' => ['/bin/true']], 'junk'], 1],
		];
	}

	/**
	 * @dataProvider malformedSystemValues
	 */
	public function testAMalformedSystemValueIsIgnoredWithALogLine(mixed $value, int $logLines): void {
		$this->systemValues[CheckStore::SYSTEM_KEY] = $value;

		$checks = $this->store()->commandChecks();

		self::assertCount($logLines, $this->warnings);
		self::assertStringContainsString('versioniq.update_checks', $this->warnings[0]);
		self::assertSame(is_array($value) && ($value[0]['name'] ?? null) === 'Good' ? ['Good'] : [], array_map(static fn (UpdateCheck $check): string => $check->name, $checks));
	}

	public function testMalformedStoredVerdictChecksAreIgnoredWithALogLine(): void {
		$this->appValues[CheckStore::KEY] = '{not json';
		self::assertSame([], $this->store()->verdictChecks());
		self::assertCount(1, $this->warnings);

		// Written with occ config:app:set, as the end-to-end test does: read leniently.
		$this->appValues[CheckStore::KEY] = json_encode([['name' => 'Local CI', 'url' => 'http://forge:8080/verdict/{appId}/{version}'], ['name' => 'No address']], JSON_THROW_ON_ERROR);
		$checks = $this->store()->verdictChecks();
		self::assertSame(['Local CI'], array_map(static fn (UpdateCheck $check): string => $check->name, $checks));
		self::assertNotSame('', $checks[0]->id);
		self::assertCount(2, $this->warnings);
	}

	public function testAllListsVerdictChecksBeforeCommandChecks(): void {
		$store = $this->store();
		$store->saveVerdictChecks([self::verdict()]);
		$this->systemValues[CheckStore::SYSTEM_KEY] = [['name' => 'Lint', 'command' => ['/bin/true']]];

		self::assertSame([UpdateCheck::KIND_VERDICT, UpdateCheck::KIND_COMMAND], array_map(static fn (UpdateCheck $check): string => $check->kind, $store->all()));
		self::assertSame(10, $store->read()['maxVerdict']);
	}
}
