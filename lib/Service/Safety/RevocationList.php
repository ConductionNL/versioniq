<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Safety;

use Exception;
use OCA\Versioniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use phpseclib\File\X509 as X509V2;
use phpseclib3\File\X509 as X509V3;
use Psr\Log\LoggerInterface;

/**
 * The newest code-signing revocation list Versioniq can trust (design D1).
 *
 * That is the `root.crl` the server shipped with, unless a list fetched from
 * the admin's address (`safety.crl_url`) validates against the server's
 * `root.crt` and carries a higher CRL number. The fetched list is kept in the
 * app data folder `codesigning` and refetched at most once a day; an empty
 * address switches fetching off. Installs and the daily recheck both read the
 * list from here, so they can never disagree.
 */
class RevocationList {
	/** App config key of the address a newer list is fetched from. */
	public const KEY_URL = 'safety.crl_url';

	/** App config key of the time the list was last fetched. */
	public const KEY_FETCHED_AT = 'safety.crl_fetched_at';

	/** Where Nextcloud publishes the current list. */
	public const DEFAULT_URL = 'https://raw.githubusercontent.com/nextcloud/server/master/resources/codesigning/root.crl';

	/** The app data folder that keeps the fetched list. */
	public const FOLDER = 'codesigning';

	/** The file the fetched list is kept in. */
	public const FILE = 'root.crl';

	/** Seconds between two fetches. */
	public const REFETCH_SECONDS = 86400;

	public function __construct(
		private IAppDataFactory $appDataFactory,
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The address a newer list is fetched from; '' when fetching is off.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#1.1
	 */
	public function url(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::KEY_URL, self::DEFAULT_URL));
	}

	/**
	 * The server's root certificates, one PEM block each.
	 *
	 * @return list<string>
	 * @throws Exception when root.crt cannot be read
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#1.1
	 */
	public function rootCertificates(): array {
		$rootCrt = self::readServerFile('root.crt');
		if ($rootCrt === null) {
			throw new Exception('Unable to load Nextcloud root certificate chain.');
		}

		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $rootCrt, $matches);

		return $matches[0];
	}

	/**
	 * The newest list that validates, loaded against the root certificates.
	 *
	 * The shipped list must validate, as at install time; a fetched list that
	 * does not validate, or is not newer, is ignored.
	 *
	 * @return X509V3|X509V2
	 * @throws Exception when the shipped list cannot be read or does not validate
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function current(): X509V3|X509V2 {
		$roots = $this->rootCertificates();
		$shippedPem = self::readServerFile('root.crl');
		if ($shippedPem === null) {
			throw new Exception('Unable to load Nextcloud certificate revocation list.');
		}

		$shipped = $this->validated($roots, $shippedPem);
		if ($shipped === null) {
			throw new Exception('Could not validate CRL signature');
		}

		$fetchedPem = $this->url() === '' ? null : $this->storedPem();
		$fetched = $fetchedPem === null ? null : $this->validated($roots, $fetchedPem);
		if ($fetched !== null && self::numberOf($fetched) > self::numberOf($shipped)) {
			return $fetched;
		}

		return $shipped;
	}

	/**
	 * Whether the newest list revokes the certificate with this serial.
	 *
	 * @throws Exception when no list can be loaded
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function isRevoked(string $serial): bool {
		return $this->current()->getRevoked($serial) !== false;
	}

	/**
	 * Fetches the list from the configured address when a day has passed
	 * since the last fetch, and keeps it when it validates and is newer than
	 * the shipped list. Fail-soft: a failed fetch keeps what was there.
	 *
	 * @return bool Whether a list was fetched and kept
	 * @spec openspec/changes/safety-withdrawn-and-revoked/tasks.md#1.1
	 */
	public function refresh(): bool {
		$url = $this->url();
		$now = $this->timeFactory->getTime();
		$last = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_FETCHED_AT, 0);
		if ($url === '' || ($last > 0 && $now - $last < self::REFETCH_SECONDS)) {
			return false;
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_FETCHED_AT, $now);
		try {
			$pem = (string)$this->clientService->newClient()->get($url, ['timeout' => 30])->getBody();

			return $this->keepIfNewer($pem);
		} catch (\Throwable $error) {
			$this->logger->warning('RevocationList: the revocation list could not be fetched', [
				'url' => $url,
				'message' => $error->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Stores $pem when it validates and carries a higher CRL number than the
	 * shipped list.
	 *
	 * @throws Exception when the shipped list or the roots cannot be read
	 */
	private function keepIfNewer(string $pem): bool {
		$roots = $this->rootCertificates();
		$candidate = $this->validated($roots, $pem);
		if ($candidate === null) {
			$this->logger->warning('RevocationList: the fetched revocation list does not validate and is ignored');

			return false;
		}

		$shippedPem = self::readServerFile('root.crl');
		$shipped = $shippedPem === null ? null : $this->validated($roots, $shippedPem);
		if ($shipped !== null && self::numberOf($candidate) <= self::numberOf($shipped)) {
			return false;
		}

		$folder = $this->folder();
		try {
			$folder->getFile(self::FILE)->putContent($pem);
		} catch (NotFoundException) {
			$folder->newFile(self::FILE, $pem);
		}

		return true;
	}

	/**
	 * The list as loaded against the roots, or null when its signature does
	 * not validate.
	 *
	 * @param list<string> $roots
	 */
	private function validated(array $roots, string $pem): X509V3|X509V2|null {
		$crl = class_exists(X509V3::class) ? new X509V3() : new X509V2();
		foreach ($roots as $root) {
			$crl->loadCA($root);
		}

		$crl->loadCRL($pem);

		return $crl->validateSignature() === true ? $crl : null;
	}

	/**
	 * The CRL number of a loaded list; -1 when it carries none.
	 */
	private static function numberOf(X509V3|X509V2 $crl): int {
		/** @var mixed $number */
		$number = method_exists($crl, 'getExtension') ? $crl->getExtension('id-ce-cRLNumber') : null;
		if (is_int($number)) {
			return $number;
		}

		if (is_object($number) && method_exists($number, 'toString')) {
			/** @psalm-suppress MixedMethodCall */
			$digits = (string)$number->toString();

			return ctype_digit($digits) ? (int)$digits : -1;
		}

		return -1;
	}

	/**
	 * The fetched list kept in app data, or null when there is none.
	 */
	private function storedPem(): ?string {
		try {
			return $this->folder()->getFile(self::FILE)->getContent();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The app data folder that keeps the fetched list.
	 */
	private function folder(): ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $appData->newFolder(self::FOLDER);
		}
	}

	/**
	 * A file of the server's resources/codesigning, or null when unreadable.
	 */
	private static function readServerFile(string $name): ?string {
		$path = \OC::$SERVERROOT . '/resources/codesigning/' . $name;
		$content = is_readable($path) ? file_get_contents($path) : false;

		return $content === false ? null : $content;
	}
}
