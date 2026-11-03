<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

require_once __DIR__ . '/../Support/DispatchesLikeNextcloud.php';

use InvalidArgumentException;
use OCA\Versioniq\Controller\SettingsController;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Settings\InstanceSettings;
use OCA\Versioniq\Tests\Unit\Support\DispatchesLikeNextcloud;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Golden over PUT /api/instance-settings: for each set of request values,
 * dispatched the way Nextcloud does, the fields handed to
 * InstanceSettings::update() (names, values and order), the audit message
 * and the response, compared with fixtures/settings-update.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\Versioniq\Controller\SettingsController
 */
final class SettingsUpdateGoldenTest extends TestCase {
	use DispatchesLikeNextcloud;

	private const FIXTURE = __DIR__ . '/fixtures/settings-update.golden.json';

	/** @var list<array<string, string>> */
	private array $updates = [];

	/** @var list<mixed> */
	private array $audits = [];

	/**
	 * @return array<string, array{params: array<string, mixed>, admin?: bool}>
	 */
	private static function cases(): array {
		return [
			'nothing sent' => ['params' => []],
			'audit retention' => ['params' => ['auditRetentionDays' => '90']],
			'artifact cache' => ['params' => ['artifactCacheKeep' => '0']],
			'app store base' => ['params' => ['appStoreApiBase' => 'https://apps.example.org/api/v1']],
			'github api base' => ['params' => ['githubApiBase' => 'https://ghe.example.org/api/v3']],
			'github web base' => ['params' => ['githubWebBase' => 'https://ghe.example.org']],
			'advisory feed' => ['params' => ['advisoryFeedUrl' => 'https://feed.example.org']],
			'max lines behind' => ['params' => ['maxLinesBehind' => '2']],
			'blank values are sent on' => ['params' => ['appStoreApiBase' => '', 'maxLinesBehind' => ' ']],
			'every field, reversed order' => ['params' => ['maxLinesBehind' => '3', 'advisoryFeedUrl' => 'https://f.example', 'githubWebBase' => 'https://w.example', 'githubApiBase' => 'https://a.example', 'appStoreApiBase' => 'https://s.example', 'artifactCacheKeep' => '5', 'auditRetentionDays' => '365']],
			'null is left alone' => ['params' => ['auditRetentionDays' => null, 'artifactCacheKeep' => '4']],
			'unknown field ignored' => ['params' => ['theme' => 'dark']],
			'rejected value' => ['params' => ['auditRetentionDays' => 'reject']],
			'nothing applied' => ['params' => ['artifactCacheKeep' => 'none']],
			'not an admin' => ['params' => ['auditRetentionDays' => '90'], 'admin' => false],
			'number instead of text' => ['params' => ['auditRetentionDays' => 90]],
			'boolean instead of text' => ['params' => ['artifactCacheKeep' => false]],
			'list instead of text' => ['params' => ['maxLinesBehind' => ['1']]],
			'url as a number' => ['params' => ['appStoreApiBase' => 1, 'githubApiBase' => 'https://a.example']],
			'wrong type from a non-admin' => ['params' => ['advisoryFeedUrl' => true], 'admin' => false],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function caseNames(): array {
		$names = [];
		foreach (array_keys(self::cases()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}

	/**
	 * @dataProvider caseNames
	 */
	public function testCase(string $name): void {
		$case = self::cases()[$name];
		$this->updates = [];
		$this->audits = [];
		$request = $this->requestWith($case['params']);
		$response = $this->dispatch($this->controller($request, $case['admin'] ?? true), 'updateInstanceSettings', $request);
		$actual = json_encode(self::describe($response) + ['updates' => $this->updates, 'audits' => $this->audits], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	private function controller(IRequest $request, bool $admin): SettingsController {
		$settings = $this->createMock(InstanceSettings::class);
		$settings->method('read')->willReturn(['auditRetentionDays' => 365, 'artifactCacheKeep' => 3, 'appStoreApiBase' => '', 'githubApiBase' => '', 'githubWebBase' => '', 'advisoryFeedUrl' => '', 'maxLinesBehind' => '']);
		$settings->method('update')->willReturnCallback(function (array $fields): array {
			$this->updates[] = $fields;
			if (in_array('reject', $fields, true)) {
				throw new InvalidArgumentException('The audit retention is out of range');
			}

			return array_keys(array_filter($fields, static fn (string $value): bool => $value !== 'none'));
		});

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (mixed ...$args): void {
			// The message is the last argument of record(), whatever shape the leading ones take.
			$this->audits[] = end($args);
		});

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new SettingsController('versioniq', $request, $settings, $audit, $groups, $session);
	}
}
