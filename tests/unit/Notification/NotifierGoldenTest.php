<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Notification;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Notification\Notifier;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden test for Notifier::prepare(): every subject, with its parameters
 * present, missing and of the wrong type, plus a foreign app and an unknown
 * subject. The l10n calls, the parsed subject and message and the returned
 * value or thrown exception are compared, case by case as JSON text, with
 * fixtures/notifier-prepare.golden.json. Set RECORD_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\Versioniq\Notification\Notifier
 */
final class NotifierGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/notifier-prepare.golden.json';

	private const PARAMETERS = [
		'pinned_to_vulnerable' => ['app' => 'someapp', 'version' => '1.2.0', 'advisory' => 'GHSA-1234'],
		'pat_expiring' => ['label' => 'CI token', 'forge' => 'github', 'daysRemaining' => 3],
		'pin_drift' => ['app' => 'someapp', 'pinnedVersion' => '1.2.0', 'observedVersion' => '1.3.0'],
		'pat_expired' => ['label' => 'CI token', 'forge' => 'codeberg'],
		'auto_update_success' => ['app' => 'someapp', 'fromVersion' => '1.2.0', 'toVersion' => '1.3.0'],
		'auto_update_failure' => ['app' => 'someapp', 'targetVersion' => '1.3.0', 'hint' => 'Check the log.'],
		'advisory_digest' => ['apps' => 2, 'advisories' => 5],
	];

	/** @var list<array<int, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array{app: string, subject: string, parameters: array<string, mixed>}>
	 */
	private static function scenarios(): array {
		$list = [];
		foreach (self::PARAMETERS as $subject => $parameters) {
			$list[$subject] = ['app' => Application::APP_ID, 'subject' => $subject, 'parameters' => $parameters];
			$list["{$subject}, no parameters"] = ['app' => Application::APP_ID, 'subject' => $subject, 'parameters' => []];
			foreach (array_keys($parameters) as $key) {
				$wrong = is_int($parameters[$key]) ? '7' : 7;
				$list["{$subject}, {$key} wrong type"] = ['app' => Application::APP_ID, 'subject' => $subject, 'parameters' => [$key => $wrong] + $parameters];
				$list["{$subject}, {$key} null"] = ['app' => Application::APP_ID, 'subject' => $subject, 'parameters' => [$key => null] + $parameters];
			}
		}
		$list['pat_expiring, one day'] = ['app' => Application::APP_ID, 'subject' => 'pat_expiring', 'parameters' => ['daysRemaining' => 1] + self::PARAMETERS['pat_expiring']];
		$list['auto_update_success, fresh install'] = ['app' => Application::APP_ID, 'subject' => 'auto_update_success', 'parameters' => ['fromVersion' => ''] + self::PARAMETERS['auto_update_success']];
		$list['advisory_digest, one app'] = ['app' => Application::APP_ID, 'subject' => 'advisory_digest', 'parameters' => ['apps' => 1, 'advisories' => 1]];
		$list['unknown subject'] = ['app' => Application::APP_ID, 'subject' => 'something_else', 'parameters' => []];
		$list['empty subject'] = ['app' => Application::APP_ID, 'subject' => '', 'parameters' => []];
		$list['foreign app'] = ['app' => 'files', 'subject' => 'pin_drift', 'parameters' => self::PARAMETERS['pin_drift']];

		return $list;
	}

	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario($scenario);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->markTestSkipped('Golden fixture recorded.');
		}

		/** @var array<string, mixed> $expected */
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertSame(array_keys($expected), array_keys($actual), 'scenario list changed');
		foreach ($actual as $name => $case) {
			self::assertSame(
				json_encode($expected[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				json_encode($case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				"scenario {$name}",
			);
		}
	}

	/**
	 * @param array{app: string, subject: string, parameters: array<string, mixed>} $scenario
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn($scenario['app']);
		$notification->method('getSubject')->willReturn($scenario['subject']);
		$notification->method('getSubjectParameters')->willReturn($scenario['parameters']);
		foreach (['setParsedSubject', 'setParsedMessage'] as $setter) {
			$notification->method($setter)->willReturnCallback(function (string $text) use ($setter, $notification): INotification {
				$this->calls[] = [$setter, $text];

				return $notification;
			});
		}

		try {
			$result = (new Notifier($this->factory()))->prepare($notification, 'nl');
			$outcome = ['returned' => $result === $notification ? 'the notification' : 'something else'];
		} catch (Throwable $error) {
			$outcome = ['error' => [$error::class, $error->getMessage()]];
		}

		return $outcome + ['calls' => $this->calls];
	}

	private function factory(): IFactory {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, mixed $parameters = []): string {
			$this->calls[] = ['t', $text, $parameters];

			return '[t] ' . vsprintf($text, is_array($parameters) ? $parameters : [$parameters]);
		});
		$l->method('n')->willReturnCallback(function (string $singular, string $plural, int $count, array $parameters = []): string {
			$this->calls[] = ['n', $singular, $plural, $count, $parameters];

			return '[n] ' . vsprintf($count === 1 ? $singular : $plural, $parameters);
		});
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $lang = null) use ($l): IL10N {
			$this->calls[] = ['l10n.get', $app, $lang];

			return $l;
		});

		return $factory;
	}
}
