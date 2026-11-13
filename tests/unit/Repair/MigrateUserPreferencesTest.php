<?php

/**
 * Tests for the repair step that copies per-user preferences from the old app id.
 *
 * @category  Test
 * @package   OCA\Versioniq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Repair;

use OCA\Versioniq\Repair\MigrateUserPreferences;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Versioniq\Repair\MigrateUserPreferences
 *
 * @spec exclude One-off app_versions->versioniq rename plumbing.
 */
final class MigrateUserPreferencesTest extends TestCase {

	/** @var list<list<mixed>> */
	private array $calls = [];

	/**
	 * Only a stored, non-empty value missing under the new id is copied, with its laziness.
	 */
	public function testCopiesOnlyMissingValuesKeepingTheirLaziness(): void {
		$stored = [
			'alice' => [
				'app_versions' => [
					'view'   => ['cards', false],
					'filter' => ['{"q":"x"}', true],
					'empty'  => ['', false],
					'kept'   => ['old', false],
					'lazyKept' => ['old', false],
					'broken' => ['x', false],
				],
				'versioniq' => ['kept' => ['new', false], 'lazyKept' => ['new', true]],
			],
			'bob' => [
				'app_versions' => ['view' => ['table', false]],
				'versioniq'    => [],
			],
		];

		$this->runStep(users: ['alice', 'bob', 'carol'], stored: $stored, unreadableKeysFor: 'carol', throwOnKey: 'broken');

		$this->assertSame(
			[
				['set', 'alice', 'view', 'cards', false],
				['set', 'alice', 'filter', '{"q":"x"}', true],
				['warning', 'Versioniq: could not migrate one user preference; leaving it under the old app id', ['key' => 'broken', 'exception' => 'read failed']],
				['set', 'bob', 'view', 'table', false],
				['warning', 'Versioniq: could not enumerate app_versions preference keys for a user; skipping that user', ['exception' => 'no keys']],
				['info', 'MigrateUserPreferences: migrated 3 preference(s); 2 already set under versioniq.'],
			],
			$this->calls
		);
	}

	/**
	 * Users without stored preferences are nothing to do.
	 */
	public function testNothingStoredIsNothingToDo(): void {
		$this->runStep(users: ['alice'], stored: ['alice' => ['app_versions' => ['empty' => ['', false]], 'versioniq' => []]], unreadableKeysFor: null, throwOnKey: null);

		$this->assertSame(
			[['info', 'MigrateUserPreferences: no stored app_versions user preferences on this install; nothing to do.']],
			$this->calls
		);
	}

	/**
	 * A failing user walk is a warning on the output and in the log, never a throw.
	 */
	public function testAFailingUserWalkLeavesThePreferencesAndWarns(): void {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->expects($this->never())->method('setValueString');
		$users = $this->createMock(IUserManager::class);
		$users->method('callForSeenUsers')->willThrowException(new RuntimeException('ldap down'));

		$this->step(userConfig: $userConfig, users: $users)->run($this->recordingOutput());

		$this->assertSame(
			[
				['warning', 'Versioniq: could not enumerate users; per-user preferences were not migrated', ['exception' => 'ldap down']],
				['output-warning', 'MigrateUserPreferences: user enumeration failed; preferences left under the app_versions app id.'],
			],
			$this->calls
		);
	}

	/**
	 * The step names what it does.
	 */
	public function testNamesWhatItDoes(): void {
		$step = $this->step(userConfig: $this->createMock(IUserConfig::class), users: $this->createMock(IUserManager::class));

		$this->assertSame('Copy Versioniq per-user preferences from the app_versions app id', $step->getName());
	}

	/**
	 * Run the step over users and their stored preferences.
	 *
	 * @param list<string> $users The seen users.
	 * @param array<string, array<string, array<string, array{0: string, 1: bool}>>> $stored Per user, per app id: key => [value, lazy].
	 * @param string|null $unreadableKeysFor The user whose keys cannot be listed.
	 * @param string|null $throwOnKey The key whose read fails.
	 */
	private function runStep(array $users, array $stored, ?string $unreadableKeysFor, ?string $throwOnKey): void {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getKeys')->willReturnCallback(
			static function (string $uid, string $app) use ($stored, $unreadableKeysFor): array {
				if ($uid === $unreadableKeysFor) {
					throw new RuntimeException('no keys');
				}
				return array_keys($stored[$uid][$app] ?? []);
			}
		);
		$userConfig->method('isLazy')->willReturnCallback(
			static function (string $uid, string $app, string $key) use ($stored, $throwOnKey): bool {
				if ($key === $throwOnKey) {
					throw new RuntimeException('read failed');
				}
				return $stored[$uid][$app][$key][1];
			}
		);
		$userConfig->method('getValueString')->willReturnCallback(
			static function (string $uid, string $app, string $key, string $default, bool $lazy) use ($stored): string {
				$entry = ($stored[$uid][$app][$key] ?? null);
				return ($entry !== null && $entry[1] === $lazy) ? $entry[0] : $default;
			}
		);
		$userConfig->method('hasKey')->willReturnCallback(
			static function (string $uid, string $app, string $key, ?bool $lazy) use ($stored): bool {
				$entry = ($stored[$uid][$app][$key] ?? null);
				return $entry !== null && $entry[1] === $lazy;
			}
		);
		$userConfig->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value, bool $lazy): bool {
				$this->assertSame('versioniq', $app);
				$this->calls[] = ['set', $uid, $key, $value, $lazy];
				return true;
			}
		);

		$manager = $this->createMock(IUserManager::class);
		$manager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($users): void {
				foreach ($users as $uid) {
					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$this->assertNull($callback($user));
				}
			}
		);

		$this->step(userConfig: $userConfig, users: $manager)->run($this->recordingOutput());
	}

	/**
	 * The step with a recording logger.
	 *
	 * @param IUserConfig $userConfig The user config.
	 * @param IUserManager $users The user manager.
	 */
	private function step(IUserConfig $userConfig, IUserManager $users): MigrateUserPreferences {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['warning', $message, $context];
			}
		);

		return new MigrateUserPreferences($userConfig, $users, $logger);
	}

	/**
	 * An output that records what the step says.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['info', $message];
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['output-warning', $message];
			}
		);
		return $output;
	}
}
