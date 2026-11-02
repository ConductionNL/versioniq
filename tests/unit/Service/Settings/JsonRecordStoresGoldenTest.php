<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Settings;

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Lkg\Lkg;
use OCA\Versioniq\Service\Lkg\LkgStore;
use OCA\Versioniq\Service\Pin\Pin;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\Policy;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for the three JSON-in-app-config stores (last-known-good, pin,
 * policy): every public method runs against a recording app config, logger
 * and audit logger, and the results plus every collaborator call are
 * compared, case by case as JSON text, with
 * fixtures/json-record-stores.golden.json. Set RECORD_GOLDEN=1 to rewrite the
 * fixture.
 *
 * @covers \OCA\Versioniq\Service\Lkg\LkgStore
 * @covers \OCA\Versioniq\Service\Pin\PinStore
 * @covers \OCA\Versioniq\Service\Policy\PolicyStore
 * @uses \OCA\Versioniq\Service\Lkg\Lkg
 * @uses \OCA\Versioniq\Service\Pin\Pin
 * @uses \OCA\Versioniq\Service\Policy\Policy
 */
final class JsonRecordStoresGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/json-record-stores.golden.json';

	private const STORED = [
		'lkg.good' => '{"version":"2.5.0","recordedAt":"2026-06-01T00:00:00+00:00","sourceId":"appstore"}',
		'lkg.bare' => '{"version":"1.0.0","recordedAt":"2026-01-01T00:00:00+00:00"}',
		'lkg.broken' => '{not json',
		'lkg.scalar' => '5',
		'lkg.invalid' => '{"version":"2.5.0"}',
		'lkg.deep' => '[[[[[[[[[[[[[[[[[[1]]]]]]]]]]]]]]]]]',
		'pin.good' => '{"version":"2.3.0","pinnedBy":"alice","pinnedAt":"2026-06-11T12:00:00+00:00","reason":"hold"}',
		'pin.drifted' => '{"version":"2.3.0","pinnedBy":"alice","pinnedAt":"2026-06-11T12:00:00+00:00","driftedTo":"2.4.0","driftedAt":"2026-06-12T00:00:00+00:00"}',
		'pin.broken' => 'nope',
		'pin.invalid' => '{"version":"2.3.0"}',
		'pin.scalar' => '"text"',
		'pin.' => '{"version":"9.9.9","pinnedBy":"x","pinnedAt":"2026-01-01T00:00:00+00:00"}',
		'pin.empty' => '',
		'pin.number' => 7,
		'pinx.other' => '{"version":"1.0.0","pinnedBy":"x","pinnedAt":"2026-01-01T00:00:00+00:00"}',
		'policy.good' => '{"level":"patch","setBy":"admin","setAt":"2026-06-01T00:00:00+00:00"}',
		'policy.broken' => '{',
		'policy.invalid' => '{"level":"patch"}',
		'policy.wrong' => '{"level":"sideways","setBy":"admin","setAt":"2026-06-01T00:00:00+00:00"}',
		'policy.deep' => '[[[[[[[[[1]]]]]]]]]',
		'policy.' => '{"level":"minor","setBy":"admin","setAt":"2026-06-01T00:00:00+00:00"}',
		'policy.null' => null,
	];

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $stored = [];

	private const APP_IDS = ['good', 'bare', 'broken', 'scalar', 'invalid', 'deep', 'drifted', 'wrong', 'missing', 'empty', 'number', 'null', ''];

	/**
	 * @return array<string, callable(self): mixed>
	 */
	private static function cases(): array {
		$cases = [];
		foreach (self::APP_IDS as $appId) {
			$cases["lkg get $appId"] = static fn (self $t): mixed => $t->lkg()->get($appId);
			$cases["pin get $appId"] = static fn (self $t): mixed => $t->pin()->get($appId);
			$cases["policy get $appId"] = static fn (self $t): mixed => $t->policy()->get($appId);
			$cases["policy levelFor $appId"] = static fn (self $t): mixed => $t->policy()->levelFor($appId);
			$cases["pin clear $appId"] = static fn (self $t): mixed => $t->pin()->clear($appId, 'bob');
			$cases["policy clear $appId"] = static fn (self $t): mixed => $t->policy()->clear($appId);
			$cases["pin markDrift $appId"] = static fn (self $t): mixed => $t->pin()->markDrift($appId, '2.4.0', '2026-07-01T00:00:00+00:00');
			$cases["pin markDrift new $appId"] = static fn (self $t): mixed => $t->pin()->markDrift($appId, '2.6.0', '2026-07-02T00:00:00+00:00');
		}
		$cases['pin all'] = static fn (self $t): mixed => $t->pin()->all();
		$cases['policy all'] = static fn (self $t): mixed => $t->policy()->all();
		$cases['lkg set'] = static fn (self $t): mixed => $t->lkg()->set('fresh', new Lkg('3.0.0', '2026-08-01T00:00:00+00:00', 'github:o/r'));
		$cases['lkg set no source'] = static fn (self $t): mixed => $t->lkg()->set('good', new Lkg('3.0.0', '2026-08-01T00:00:00+00:00'));
		$cases['pin set'] = static fn (self $t): mixed => $t->pin()->set('fresh', new Pin('1.2.3', 'carol', '2026-08-01T00:00:00+00:00', 'freeze', '1.3.0', '2026-08-02T00:00:00+00:00'));
		$cases['pin set no reason'] = static fn (self $t): mixed => $t->pin()->set('good', new Pin('1.2.3', 'carol', '2026-08-01T00:00:00+00:00'));
		$cases['policy set'] = static fn (self $t): mixed => $t->policy()->set('fresh', new Policy('minor', 'dave', '2026-08-01T00:00:00+00:00'));

		return $cases;
	}

	public function lkg(): LkgStore {
		return new LkgStore($this->config(), $this->logger());
	}

	public function pin(): PinStore {
		return new PinStore($this->config(), $this->auditLogger(), $this->logger());
	}

	public function policy(): PolicyStore {
		return new PolicyStore($this->config(), $this->logger());
	}

	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(function (string $app, string $key, string $default = ''): string {
			$this->calls[] = ['config.getValueString', $app, $key, $default];
			$value = $this->stored[$key] ?? $default;

			return is_string($value) ? $value : $default;
		});
		$config->method('getAllValues')->willReturnCallback(function (string $app, string $prefix = ''): array {
			$this->calls[] = ['config.getAllValues', $app, $prefix];

			return array_filter($this->stored, static fn (string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->calls[] = ['config.setValueString', $app, $key, $value];

			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			$this->calls[] = ['config.deleteKey', $app, $key];
		});

		return $config;
	}

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->calls[] = ['logger.warning', (string)$message, $context];
		});

		return $logger;
	}

	private function auditLogger(): AuditLogger {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (mixed ...$args): void {
			$this->calls[] = ['audit.record', ...$args];
		});

		return $audit;
	}

	private static function export(mixed $value): mixed {
		if (is_object($value) && method_exists($value, 'toArray')) {
			return [$value::class => $value->toArray()];
		}
		if (is_array($value)) {
			return array_map(static fn (mixed $item): mixed => self::export($item), $value);
		}

		return $value;
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
		$this->stored = self::STORED;
		$this->calls = [];
		try {
			$result = self::export((self::cases()[$name])($this));
		} catch (\Throwable $error) {
			$result = ['threw' => $error::class, 'message' => $error->getMessage()];
		}
		$actual = json_encode(['result' => $result, 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}
}
