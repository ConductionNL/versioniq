<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */

namespace OCA\Versioniq\Tests\Unit\Service\Safety;

use OCA\Versioniq\Service\Safety\RevocationList;
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
use phpseclib3\File\X509;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The newest trusted revocation list (design D1): a valid newer list wins, a
 * list with a bad signature and an older list are ignored.
 *
 * The phpseclib X509 stub is scripted so a list's text decides its outcome:
 * `BADSIG` fails the signature, `NUM:<n>` is its CRL number and
 * `REVOKED:<serial>` revokes that serial.
 *
 * @covers \OCA\Versioniq\Service\Safety\RevocationList
 */
final class RevocationListTest extends TestCase {
	private string $root = '';

	private string $savedServerRoot = '';

	/** @var array<string, string> */
	private array $files = [];

	/** @var array<string, string|int> */
	private array $config = [];

	private int $now = 1_800_000_000;

	/** @var string|\Throwable */
	private string|\Throwable $served = '';

	private int $fetches = 0;

	protected function setUp(): void {
		$this->savedServerRoot = (string)\OC::$SERVERROOT;
		$this->root = sys_get_temp_dir() . '/vq-crl-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/resources/codesigning', 0o777, true);
		file_put_contents($this->root . '/resources/codesigning/root.crt', "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n");
		$this->ship("NUM:10\n");
		\OC::$SERVERROOT = $this->root;

		$loaded = new \WeakMap();
		X509::$handler = static function (X509 $x509, string $method, array $args) use ($loaded): mixed {
			switch ($method) {
				case 'loadCRL':
					$loaded[$x509] = (string)$args[0];

					return [];
				case 'validateSignature':
					return !str_contains($loaded[$x509] ?? '', 'BADSIG');
				case 'getExtension':
					return preg_match('/NUM:(\d+)/', $loaded[$x509] ?? '', $m) === 1 ? (int)$m[1] : false;
				case 'getRevoked':
					return str_contains($loaded[$x509] ?? '', 'REVOKED:' . $args[0] . "\n") ? ['serial' => $args[0]] : false;
				default:
					return null;
			}
		};
	}

	protected function tearDown(): void {
		X509::$handler = null;
		\OC::$SERVERROOT = $this->savedServerRoot;
		foreach (['root.crt', 'root.crl'] as $file) {
			@unlink($this->root . '/resources/codesigning/' . $file);
		}
		@rmdir($this->root . '/resources/codesigning');
		@rmdir($this->root . '/resources');
		@rmdir($this->root);
	}

	private function ship(string $crl): void {
		file_put_contents($this->root . '/resources/codesigning/root.crl', $crl);
	}

	private function list(?LoggerInterface $logger = null): RevocationList {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn (): string => $this->files[RevocationList::FILE]);
		$file->method('putContent')->willReturnCallback(function ($content): void {
			$this->files[RevocationList::FILE] = (string)$content;
		});

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(function (string $name) use ($file): ISimpleFile {
			if (!isset($this->files[$name])) {
				throw new NotFoundException($name);
			}

			return $file;
		});
		$folder->method('newFile')->willReturnCallback(function (string $name, $content) use ($file): ISimpleFile {
			$this->files[$name] = (string)$content;

			return $file;
		});

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with(RevocationList::FOLDER)->willReturn($folder);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('versioniq')->willReturn($appData);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturnCallback(fn (): string => is_string($this->served) ? $this->served : '');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($response): IResponse {
			$this->fetches++;
			if ($this->served instanceof \Throwable) {
				throw $this->served;
			}

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->config[$key] = $value;

			return true;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new RevocationList($factory, $clients, $appConfig, $time, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testTheShippedListAppliesWhenNothingWasFetched(): void {
		$this->ship("NUM:10\nREVOKED:111\n");
		$list = $this->list();

		self::assertTrue($list->isRevoked('111'));
		self::assertFalse($list->isRevoked('222'));
	}

	public function testAValidNewerListIsKeptAndWins(): void {
		$this->served = "NUM:11\nREVOKED:222\n";
		$list = $this->list();

		self::assertTrue($list->refresh());
		self::assertSame("NUM:11\nREVOKED:222\n", $this->files[RevocationList::FILE]);
		self::assertTrue($list->isRevoked('222'));
		self::assertSame(1, $this->fetches);
	}

	public function testAListWithABadSignatureIsIgnored(): void {
		$this->served = "NUM:99\nBADSIG\nREVOKED:222\n";
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('RevocationList: the fetched revocation list does not validate and is ignored');
		$list = $this->list($logger);

		self::assertFalse($list->refresh());
		self::assertArrayNotHasKey(RevocationList::FILE, $this->files);
		self::assertFalse($list->isRevoked('222'));
	}

	public function testAStoredListWithABadSignatureIsNotUsed(): void {
		$this->files[RevocationList::FILE] = "NUM:99\nBADSIG\nREVOKED:222\n";

		self::assertFalse($this->list()->isRevoked('222'));
	}

	public function testAnOlderListIsIgnored(): void {
		$this->served = "NUM:9\nREVOKED:222\n";
		$list = $this->list();

		self::assertFalse($list->refresh());
		self::assertArrayNotHasKey(RevocationList::FILE, $this->files);
		self::assertFalse($list->isRevoked('222'));
	}

	public function testAStoredListOlderThanANewServerReleaseLoses(): void {
		$this->files[RevocationList::FILE] = "NUM:11\nREVOKED:222\n";
		$this->ship("NUM:12\nREVOKED:333\n");
		$list = $this->list();

		self::assertFalse($list->isRevoked('222'));
		self::assertTrue($list->isRevoked('333'));
	}

	public function testAnEmptyAddressSwitchesFetchingOff(): void {
		$this->config[RevocationList::KEY_URL] = '';
		$this->files[RevocationList::FILE] = "NUM:11\nREVOKED:222\n";
		$this->served = "NUM:12\nREVOKED:333\n";
		$list = $this->list();

		self::assertSame('', $list->url());
		self::assertFalse($list->refresh());
		self::assertSame(0, $this->fetches);
		self::assertFalse($list->isRevoked('222'));
	}

	public function testTheDefaultAddressIsNextcloudsPublishedList(): void {
		self::assertSame(RevocationList::DEFAULT_URL, $this->list()->url());
	}

	public function testTheListIsFetchedAtMostOnceADay(): void {
		$this->served = "NUM:11\n";
		$list = $this->list();

		self::assertTrue($list->refresh());
		$this->now += RevocationList::REFETCH_SECONDS - 1;
		self::assertFalse($list->refresh());
		self::assertSame(1, $this->fetches);

		$this->now += 1;
		$this->served = "NUM:12\n";
		self::assertTrue($list->refresh());
		self::assertSame(2, $this->fetches);
		self::assertSame("NUM:12\n", $this->files[RevocationList::FILE]);
	}

	public function testAFailedFetchKeepsWhatWasThere(): void {
		$this->files[RevocationList::FILE] = "NUM:11\nREVOKED:222\n";
		$this->served = new \RuntimeException('connection refused');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'RevocationList: the revocation list could not be fetched',
			['url' => RevocationList::DEFAULT_URL, 'message' => 'connection refused'],
		);
		$list = $this->list($logger);

		self::assertFalse($list->refresh());
		self::assertTrue($list->isRevoked('222'));
	}

	public function testAShippedListThatDoesNotValidateIsRefused(): void {
		$this->ship("NUM:10\nBADSIG\n");

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Could not validate CRL signature');
		$this->list()->current();
	}

	public function testTheRootCertificatesAreSplitIntoBlocks(): void {
		file_put_contents(
			$this->root . '/resources/codesigning/root.crt',
			"-----BEGIN CERTIFICATE-----\nA\n-----END CERTIFICATE-----\n-----BEGIN CERTIFICATE-----\nB\n-----END CERTIFICATE-----\n",
		);

		self::assertSame([
			"-----BEGIN CERTIFICATE-----\nA\n-----END CERTIFICATE-----",
			"-----BEGIN CERTIFICATE-----\nB\n-----END CERTIFICATE-----",
		], $this->list()->rootCertificates());
	}
}
