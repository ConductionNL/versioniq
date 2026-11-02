<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ApiController;
use OCA\Versioniq\Db\AuditEntryMapper;
use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Advisory\AdvisoryResultStore;
use OCA\Versioniq\Service\Advisory\AdvisorySettingsStore;
use OCA\Versioniq\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Discovery\DiscoveryAggregator;
use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Pat\PatDeeplinkBuilder;
use OCA\Versioniq\Service\Pat\PatExpiryEvaluator;
use OCA\Versioniq\Service\Pat\PatManager;
use OCA\Versioniq\Service\Pat\PatValidator;
use OCA\Versioniq\Service\Pat\ValidationResult;
use OCA\Versioniq\Service\Pin\PinStore;
use OCA\Versioniq\Service\Policy\PolicyStore;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\ServerVersion;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for creating a PAT through ApiController::createPat() and the
 * real PatManager::create(): request, session, validator, crypto and mapper
 * are recording doubles, and the response plus every collaborator call and
 * the inserted row (timestamps masked) are compared, case by case as JSON
 * text, with fixtures/pat-create.golden.json. Set RECORD_GOLDEN=1 to rewrite
 * the fixture.
 *
 * The session user is `admin`, and the group manager answers true only for
 * that uid; a forged `ownerUid`/`userId` request parameter must not change
 * the stored owner.
 *
 * @covers \OCA\Versioniq\Controller\ApiController
 * @covers \OCA\Versioniq\Service\Pat\PatManager
 * @uses \OCA\Versioniq\Service\Pat\ValidationResult
 * @uses \OCA\Versioniq\Db\Pat
 */
final class PatCreateGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/pat-create.golden.json';

	/** @var list<array<array-key, mixed>> */
	private array $calls = [];

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function cases(): array {
		$base = ['label' => 'releases', 'targetPattern' => 'ConductionNL/*', 'token' => 'ghp_secrettoken1234567890abcdef1234567890', 'ownerUid' => 'mallory', 'userId' => 'mallory'];
		$accepted = ValidationResult::accepted(['repo', 'read:org'], ['broad_scope'], '2027-01-01T00:00:00Z');

		return [
			'github classic' => ['params' => $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'github fine grained no expiry' => ['params' => $base, 'result' => ValidationResult::accepted([], ['unverifiable_scope'], null), 'kind' => Pat::KIND_FINE_GRAINED],
			'codeberg' => ['params' => $base + ['forge' => 'codeberg'], 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'gitea' => ['params' => ['forge' => 'gitea'] + $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'short token' => ['params' => ['token' => 'abc'] + $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'eight char token' => ['params' => ['token' => 'abcdefgh'] + $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'trimmed values' => ['params' => ['label' => '  spaced  ', 'targetPattern' => ' Org/repo '] + $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'missing label' => ['params' => ['label' => ''] + $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'missing token' => ['params' => ['token' => ''] + $base, 'result' => $accepted, 'kind' => Pat::KIND_CLASSIC],
			'rejected' => ['params' => $base, 'result' => ValidationResult::rejected('Token is invalid or revoked.'), 'kind' => Pat::KIND_CLASSIC],
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
		$spec = self::cases()[$name];
		$this->calls = [];
		$response = $this->controller($spec)->createPat();
		$actual = json_encode(['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$actual = (string)preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '<now>', $actual);

		$fixture = is_file(self::FIXTURE) ? (array)json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$fixture[$name] = $actual;
			ksort($fixture);
			file_put_contents(self::FIXTURE, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		}

		$this->assertArrayHasKey($name, $fixture);
		$this->assertSame($fixture[$name], $actual);
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function controller(array $spec): ApiController {
		$params = $spec['params'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$validator = $this->createMock(PatValidator::class);
		$validator->method('validate')->willReturnCallback(function (string $token, string $forge) use ($spec): ValidationResult {
			$this->calls[] = ['validator.validate', $token, $forge];

			return $spec['result'];
		});
		$validator->method('detectKind')->willReturnCallback(function (string $token) use ($spec): string {
			$this->calls[] = ['validator.detectKind', $token];

			return $spec['kind'];
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(function (string $plaintext): string {
			$this->calls[] = ['crypto.encrypt', $plaintext];

			return 'enc(' . strlen($plaintext) . ')';
		});
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('insert')->willReturnCallback(function (Pat $pat): Pat {
			$this->calls[] = ['mapper.insert', [
				'ownerUid' => $pat->getOwnerUid(),
				'label' => $pat->getLabel(),
				'kind' => $pat->getKind(),
				'forge' => $pat->getForge(),
				'targetPattern' => $pat->getTargetPattern(),
				'encryptedToken' => $pat->getEncryptedToken(),
				'tokenHint' => $pat->getTokenHint(),
				'sharedWithAdmins' => $pat->getSharedWithAdmins(),
				'lastValidatedScopes' => $pat->getLastValidatedScopes(),
				'expiresAt' => $pat->getExpiresAt(),
				'createdAt' => $pat->getCreatedAt(),
				'updated' => $pat->getUpdatedFields(),
			]];
			$pat->setId(41);

			return $pat;
		});
		$expiry = $this->createMock(PatExpiryEvaluator::class);
		$expiry->method('evaluate')->willReturn(['state' => 'ok', 'daysRemaining' => null]);

		return new ApiController(
			'versioniq',
			$request,
			$this->createMock(InstallerService::class),
			$groupManager,
			$session,
			(new ReflectionClass(ServerVersion::class))->newInstanceWithoutConstructor(),
			$this->createMock(PatMapper::class),
			new PatManager($mapper, $crypto),
			$validator,
			$this->createMock(PatDeeplinkBuilder::class),
			$expiry,
			$this->createMock(DiscoveryAggregator::class),
			$this->createMock(AdvisoryResultStore::class),
			$this->createMock(AdvisorySettingsStore::class),
			$this->createMock(AuditEntryMapper::class),
			$this->createMock(PinStore::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(PolicyStore::class),
			$this->createMock(AutoUpdateSettingsStore::class),
			$this->createMock(ArtifactCache::class),
		);
	}
}
