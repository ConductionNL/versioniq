<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Safety;

require_once __DIR__ . '/../../Support/CodeSigningFixtures.php';

use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Cache\ArtifactCache;
use OCA\Versioniq\Service\Installer\InstallFinalizer;
use OCA\Versioniq\Service\Installer\MigrationDiffer;
use OCA\Versioniq\Service\Safety\CrlDocument;
use OCA\Versioniq\Service\Safety\RevocationList;
use OCA\Versioniq\Service\SelectedReleaseInstallerService;
use OCA\Versioniq\Tests\Unit\Support\CodeSigningFixtures;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IUserSession;
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The revocation list installs and the recheck read (safety-withdrawn-and-revoked
 * 1.1, design D1), with real keys and lists made by OpenSSL: a validly signed
 * newer list wins, a list with a bad signature and an older list are ignored,
 * and an empty address switches fetching off.
 *
 * @covers \OCA\Versioniq\Service\Safety\RevocationList
 * @covers \OCA\Versioniq\Service\Safety\CrlDocument
 * @covers \OCA\Versioniq\Service\Safety\DerElement
 * @covers \OCA\Versioniq\Service\SelectedReleaseInstallerService
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */
final class RevocationListTest extends TestCase {
	private const NOW = 1_800_000_000;

	/** @var array{key: \OpenSSLAsymmetricKey, cert: string} */
	private static array $authority;

	private string $dir;

	/** @var array<string, string|int> */
	private array $config = [];

	private ?string $stored = null;

	/** The body the address answers with, and its status. */
	private string $served = '';

	private int $status = 200;

	private int $fetches = 0;

	public static function setUpBeforeClass(): void {
		self::$authority = CodeSigningFixtures::authority();
	}

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/vq-crl-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		file_put_contents($this->dir . '/root.crt', self::$authority['cert']);
		file_put_contents($this->dir . '/root.crl', CodeSigningFixtures::revocationList(self::$authority, 10, [7]));
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->dir . '/*') ?: []);
		rmdir($this->dir);
	}

	public function testTheShippedListAppliesWhileNothingWasFetched(): void {
		$list = $this->list()->current();

		self::assertSame('A', $list->number);
		self::assertTrue($list->revokes('07'));
		self::assertFalse($list->revokes('2A'));
	}

	public function testAValidNewerListIsKeptAndWins(): void {
		$this->served = CodeSigningFixtures::revocationList(self::$authority, 11, [7, 42]);

		self::assertTrue($this->list()->refreshIfDue());
		self::assertNotNull($this->stored);
		self::assertSame(self::NOW, $this->config[RevocationList::KEY_FETCHED_AT]);

		$current = $this->list()->current();
		self::assertSame('B', $current->number);
		self::assertTrue($current->revokes('2a'));
	}

	public function testAListWithABadSignatureIsIgnored(): void {
		$impostor = CodeSigningFixtures::authority();
		$this->served = CodeSigningFixtures::revocationList($impostor, 99, [42]);

		self::assertFalse($this->list()->refreshIfDue());
		self::assertNull($this->stored);
		self::assertFalse($this->list()->current()->revokes('2A'));
	}

	public function testAStoredListWhoseSignatureNoLongerValidatesIsIgnored(): void {
		$this->stored = CodeSigningFixtures::revocationList(CodeSigningFixtures::authority(), 99, [42]);

		self::assertSame('A', $this->list()->current()->number);
	}

	public function testAnOlderListIsIgnored(): void {
		$this->served = CodeSigningFixtures::revocationList(self::$authority, 9, [42]);

		self::assertFalse($this->list()->refreshIfDue());
		self::assertNull($this->stored);
		self::assertSame('A', $this->list()->current()->number);
	}

	public function testAListWithoutANumberIsNeverNewer(): void {
		$this->served = CodeSigningFixtures::revocationList(self::$authority, null, [42]);

		self::assertFalse($this->list()->refreshIfDue());
		self::assertNull($this->stored);
	}

	public function testAListNoNewerThanTheOneAlreadyKeptIsIgnored(): void {
		$this->stored = CodeSigningFixtures::revocationList(self::$authority, 12, [42]);
		$this->served = CodeSigningFixtures::revocationList(self::$authority, 11, []);

		self::assertFalse($this->list()->refreshIfDue());
		self::assertSame('C', $this->list()->current()->number);
	}

	public function testAnEmptyAddressSwitchesFetchingOffAndTheKeptListWithIt(): void {
		$this->config[RevocationList::KEY_URL] = '';
		$this->stored = CodeSigningFixtures::revocationList(self::$authority, 11, [42]);

		self::assertNull($this->list()->url());
		self::assertFalse($this->list()->refreshIfDue());
		self::assertSame(0, $this->fetches);
		self::assertSame('A', $this->list()->current()->number);
	}

	public function testTheListIsFetchedAtMostOnceADay(): void {
		$this->config[RevocationList::KEY_FETCHED_AT] = self::NOW - RevocationList::REFETCH_SECONDS + 60;

		self::assertFalse($this->list()->refreshIfDue());
		self::assertSame(0, $this->fetches);
	}

	public function testTheDefaultAddressIsTheServerRepositoryAndAnOverrideIsUsed(): void {
		self::assertSame(RevocationList::DEFAULT_URL, $this->list()->url());

		$this->config[RevocationList::KEY_URL] = ' https://mirror.example.org/root.crl ';
		self::assertSame('https://mirror.example.org/root.crl', $this->list()->url());
	}

	public function testAFailedFetchKeepsTheListInUse(): void {
		$this->status = 503;

		self::assertFalse($this->list()->refreshIfDue());
		self::assertSame(1, $this->fetches);
		self::assertSame('A', $this->list()->current()->number);
	}

	public function testAMissingShippedListIsAnError(): void {
		unlink($this->dir . '/root.crl');

		$this->expectException(\RuntimeException::class);
		$this->list()->current();
	}

	public function testAnRsaSignedListInDerValidates(): void {
		$authority = CodeSigningFixtures::authority('RSA Authority', true);
		$pem = CodeSigningFixtures::revocationList($authority, 4232, [0x1002]);
		$der = (string)base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $pem));

		$list = CrlDocument::parse($der);
		self::assertSame('1088', $list->number);
		self::assertTrue($list->revokes('1002'));
		self::assertTrue($list->isSignedByOneOf(self::$authority['cert'] . $authority['cert']));
		self::assertFalse($list->isSignedByOneOf(self::$authority['cert']));
	}

	public function testAnInstallChecksAgainstTheSameList(): void {
		if (!class_exists(X509::class) || !property_exists(X509::class, 'handler')) {
			$this->markTestSkipped('Runs on the unit-only bootstrap, whose X509 class it scripts.');
		}
		$this->stored = CodeSigningFixtures::revocationList(self::$authority, 11, [42]);
		$loaded = [];
		X509::$handler = static function (X509 $x509, string $method, array $args) use (&$loaded): mixed {
			if ($method === 'loadCRL') {
				$loaded[] = $args[0];
			}

			return match ($method) {
				'loadX509' => ['tbsCertificate' => ['serialNumber' => new class {
					public function toString(): string {
						return '42';
					}
				}]],
				'getRevoked' => ['revoked'],
				default => true,
			};
		};
		$installer = new SelectedReleaseInstallerService(
			$this->createMock(InstallFinalizer::class),
			$this->createMock(AuditLogger::class),
			$this->createMock(IUserSession::class),
			$this->createMock(MigrationDiffer::class),
			$this->createMock(ArtifactCache::class),
			revocationList: $this->list(),
		);

		try {
			self::assertFalse($installer->trustsCertificate('badapp', CodeSigningFixtures::appCertificate(self::$authority, 'badapp', 42)));
		} finally {
			X509::$handler = null;
		}
		self::assertSame([$this->list()->current()->pem], $loaded);
		self::assertSame('B', CrlDocument::parse($loaded[0])->number);
	}

	public function testTextThatIsNoListIsRefused(): void {
		$this->expectException(\UnexpectedValueException::class);
		CrlDocument::parse("-----BEGIN X509 CRL-----\nMAMCAQE=\n-----END X509 CRL-----\n");
	}

	private function list(): RevocationList {
		return new RevocationList($this->appData(), $this->appConfig(), $this->client(), $this->clock(), new NullLogger(), $this->dir);
	}

	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => array_key_exists($key, $this->config));
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default));
		$config->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default));
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->config[$key] = $value;

			return true;
		});

		return $config;
	}

	private function appData(): IAppDataFactory {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => (string)$this->stored);
		$file->method('putContent')->willReturnCallback(function (string $content): void {
			$this->stored = $content;
		});
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($file): ISimpleFile {
			self::assertSame(RevocationList::FILE, $name);
			if ($this->stored === null) {
				throw new NotFoundException();
			}

			return $file;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, string $content) use ($file): ISimpleFile {
			$this->stored = $content;

			return $file;
		});
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with(RevocationList::FOLDER)->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('versioniq')->willReturn($appData);

		return $factory;
	}

	private function client(): IClientService {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturnCallback(fn (): int => $this->status);
		$response->method('getBody')->willReturnCallback(fn (): string => $this->served);
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($response): IResponse {
			$this->fetches++;

			return $response;
		});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		return $service;
	}

	private function clock(): ITimeFactory {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(self::NOW);

		return $clock;
	}
}
