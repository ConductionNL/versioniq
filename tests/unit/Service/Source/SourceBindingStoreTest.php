<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceBindingStore;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SourceBindingStoreTest extends TestCase {
	private function auditLogger(): AuditLogger {
		return $this->createMock(AuditLogger::class);
	}

	private function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}

	private function userSession(string $uid = 'alice'): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	private function makeStore(
		IAppConfig $config,
		?AuditLogger $auditLogger = null,
		?LoggerInterface $logger = null,
	): SourceBindingStore {
		return new SourceBindingStore(
			$config,
			$auditLogger ?? $this->auditLogger(),
			$this->userSession(),
			$logger ?? $this->logger(),
		);
	}

	public function testGetReturnsNullWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = $this->makeStore($config);

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsBindingForValidJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
			'assetPattern' => '*.tar.gz',
		], JSON_THROW_ON_ERROR));

		$store = $this->makeStore($config);
		$binding = $store->get('openregister');

		$this->assertNotNull($binding);
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
	}

	public function testGetReturnsNullOnMalformedJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not valid json');

		$store = $this->makeStore($config);

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullOnInvalidBinding(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			// missing owner/repo
		], JSON_THROW_ON_ERROR));

		$store = $this->makeStore($config);

		$this->assertNull($store->get('openregister'));
	}

	public function testSetWritesJson(): void {
		$captured = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'source.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = $this->makeStore($config);
		$store->set('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		$decoded = json_decode((string)$captured, true);
		$this->assertIsArray($decoded);
		$this->assertSame('github-release', $decoded['kind']);
		$this->assertSame('ConductionNL', $decoded['owner']);
		$this->assertSame('openregister', $decoded['repo']);
	}

	public function testSetRecordsABindSourceAuditEntryWithoutAPreviousMessage(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(
				'alice',
				'openregister',
				AuditLogger::OPERATION_BIND_SOURCE,
				null,
				null,
				'github:ConductionNL/openregister',
				AuditLogger::STATUS_SUCCESS,
				null,
			);

		$store = $this->makeStore($config, $auditLogger);
		$store->set('openregister', SourceBinding::github('ConductionNL', 'openregister'));
	}

	public function testSetOnRebindNamesThePreviousSourceIdInTheMessage(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
		], JSON_THROW_ON_ERROR));

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(
				'alice',
				'openregister',
				AuditLogger::OPERATION_BIND_SOURCE,
				null,
				null,
				'appstore',
				AuditLogger::STATUS_SUCCESS,
				$this->callback(fn (?string $message): bool => $message !== null && str_contains($message, 'github:ConductionNL/openregister')),
			);

		$store = $this->makeStore($config, $auditLogger);
		$store->set('openregister', SourceBinding::appStore());
	}

	public function testClearDeletesValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('deleteKey')
			->with(Application::APP_ID, 'source.openregister');

		$store = $this->makeStore($config);
		$store->clear('openregister');
	}

	public function testListBoundAppIdsReturnsOnlyAppsWithASourceKey(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getKeys')->with(Application::APP_ID)->willReturn([
			'source.hermiq',
			'advisory.interval_hours',
			'source.deck',
			'pin.deck',
			'source.',
		]);

		$store = $this->makeStore($config);

		$this->assertSame(['hermiq', 'deck'], $store->listBoundAppIds());
	}

	// --- Recorded SHA-256 lifecycle: "Recorded digests are binding-scoped and surfaced" ---

	private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function testSetOnSameSourcePreservesPreviouslyRecordedDigests(): void {
		$existing = SourceBinding::github('ConductionNL', 'openregister')->withRecordedSha('2.3.0', self::SHA_A);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode($existing->toArray(), JSON_THROW_ON_ERROR));

		$captured = null;
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'source.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		// A "fresh" binding for the *same* owner/repo, as the explicit bind
		// endpoint would construct (e.g. after changing only the assetPattern)
		// — it carries no sha256 map of its own.
		$fresh = SourceBinding::github('ConductionNL', 'openregister', 'openregister-*.tar.gz');

		$store = $this->makeStore($config);
		$store->set('openregister', $fresh);

		$decoded = json_decode((string)$captured, true);
		$this->assertSame(self::SHA_A, $decoded['sha256']['2.3.0'] ?? null);
		$this->assertSame('openregister-*.tar.gz', $decoded['assetPattern']);
	}

	public function testSetOnDifferentSourceDiscardsPreviouslyRecordedDigests(): void {
		$existing = SourceBinding::github('ConductionNL', 'openregister')->withRecordedSha('2.3.0', self::SHA_A);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode($existing->toArray(), JSON_THROW_ON_ERROR));

		$captured = null;
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'source.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = $this->makeStore($config);
		$store->set('openregister', SourceBinding::github('myorg', 'openregister-fork'));

		$decoded = json_decode((string)$captured, true);
		$this->assertArrayNotHasKey('sha256', $decoded);
	}

	public function testSetKeepsAFreshlyRecordedDigestOverAStalePreviousOne(): void {
		$existing = SourceBinding::github('ConductionNL', 'openregister')->withRecordedSha('2.3.0', self::SHA_A);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode($existing->toArray(), JSON_THROW_ON_ERROR));

		$captured = null;
		$config->method('setValueString')
			->with(
				Application::APP_ID,
				'source.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		// The incoming binding already carries a *different* digest for the
		// same version (e.g. an accepted override) — the merge must not
		// clobber it with the stale previous one.
		$incoming = SourceBinding::github('ConductionNL', 'openregister')->withRecordedSha('2.3.0', self::SHA_B);

		$store = $this->makeStore($config);
		$store->set('openregister', $incoming);

		$decoded = json_decode((string)$captured, true);
		$this->assertSame(self::SHA_B, $decoded['sha256']['2.3.0'] ?? null);
	}

	public function testGetLogsWarningWhenInvalidShaEntriesAreDropped(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
			'sha256' => [
				'2.3.0' => self::SHA_A,
				'2.4.0' => 'not-a-valid-digest',
			],
		], JSON_THROW_ON_ERROR));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$store = $this->makeStore($config, null, $logger);
		$binding = $store->get('openregister');

		$this->assertNotNull($binding);
		$this->assertSame(self::SHA_A, $binding->getRecordedSha('2.3.0'));
		$this->assertNull($binding->getRecordedSha('2.4.0'));
	}

	public function testGetDoesNotLogWhenAllShaEntriesAreValid(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
			'sha256' => ['2.3.0' => self::SHA_A],
		], JSON_THROW_ON_ERROR));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$store = $this->makeStore($config, null, $logger);
		$store->get('openregister');
	}
}
