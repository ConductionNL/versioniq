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

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\Settings\AppDataFiles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use RuntimeException;
use Throwable;

/**
 * The one code-signing revocation list installs and the daily recheck read
 * (safety-withdrawn-and-revoked, design D1): the `root.crl` the server
 * shipped, unless a list fetched from `safety.crl_url` validates against the
 * server's `root.crt` and carries a higher CRL number.
 *
 * The address is an instance setting: unset reads the list in the Nextcloud
 * server repository, an empty value switches fetching off (and the shipped
 * list alone applies). A fetched list is kept in app data folder
 * `codesigning` and fetched again at most once a day. It is checked again
 * every time it is read, so a stored list that no longer validates is
 * ignored: the list never gets weaker than the shipped one.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 *
 * @psalm-api
 */
class RevocationList {
	/** The `root.crl` on the main branch of the Nextcloud server repository. */
	public const DEFAULT_URL = 'https://raw.githubusercontent.com/nextcloud/server/master/resources/codesigning/root.crl';

	/** The address to fetch from; absent reads the default, '' switches fetching off. */
	public const KEY_URL = 'safety.crl_url';

	/** When the address was last asked, a Unix time. */
	public const KEY_FETCHED_AT = 'safety.crl_fetched_at';

	public const FOLDER = 'codesigning';
	public const FILE = 'root.crl';
	public const REFETCH_SECONDS = 86400;

	private const FETCH_TIMEOUT_SECONDS = 30;

	public function __construct(
		private AppDataFiles $appData,
		private IAppConfig $config,
		private IClientService $clientService,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * The address lists are fetched from, or null when an admin switched fetching off.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function url(): ?string {
		if ($this->config->hasKey(Application::APP_ID, self::KEY_URL) === false) {
			return self::DEFAULT_URL;
		}

		$url = trim($this->config->getValueString(Application::APP_ID, self::KEY_URL, ''));

		return $url === '' ? null : $url;
	}

	/**
	 * The server's code-signing root certificates, PEM.
	 *
	 * @throws RuntimeException when they cannot be read
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function rootCertificates(): string {
		return $this->readShipped('root.crt', 'Unable to load Nextcloud root certificate chain.');
	}

	/**
	 * The newest list that can be trusted: the fetched one when it validates
	 * and is newer than the shipped one, else the shipped one.
	 *
	 * @throws RuntimeException when the shipped list cannot be read
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function current(): CrlDocument {
		$shipped = $this->shipped();
		$fetched = $this->url() === null ? null : $this->stored();

		return $fetched !== null && $this->isTrustedUpdateOf($fetched, $shipped) ? $fetched : $shipped;
	}

	/**
	 * Fetches the list from the address when the last fetch is a day old or
	 * more, and keeps it when it validates and is newer than the list in use.
	 * Does nothing while fetching is off. A list that cannot be trusted is
	 * ignored; either way the list in use stays as it was.
	 *
	 * @return bool Whether a newer list was kept.
	 * @throws RuntimeException when the list cannot be fetched or read; the caller logs it
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function refreshIfDue(): bool {
		$url = $this->url();
		$now = $this->timeFactory->getTime();
		if ($url === null || $now - $this->config->getValueInt(Application::APP_ID, self::KEY_FETCHED_AT, 0) < self::REFETCH_SECONDS) {
			return false;
		}

		$this->config->setValueInt(Application::APP_ID, self::KEY_FETCHED_AT, $now);
		try {
			$fetched = CrlDocument::parse($this->fetch($url));
		} catch (Throwable $error) {
			throw new RuntimeException(sprintf('The revocation list at %s could not be fetched: %s', $url, $error->getMessage()), 0, $error);
		}
		if (!$this->isTrustedUpdateOf($fetched, $this->current())) {
			return false;
		}

		$this->appData->write(self::FOLDER . '/' . self::FILE, $fetched->pem);

		return true;
	}

	/**
	 * Whether $candidate is signed by a root certificate and newer than $base.
	 */
	private function isTrustedUpdateOf(CrlDocument $candidate, CrlDocument $base): bool {
		return $candidate->isNewerThan($base) && $candidate->isSignedByOneOf($this->rootCertificates());
	}

	/**
	 * @throws RuntimeException when the shipped list cannot be read or parsed
	 */
	private function shipped(): CrlDocument {
		try {
			return CrlDocument::parse($this->readShipped('root.crl', 'Unable to load Nextcloud certificate revocation list.'));
		} catch (\UnexpectedValueException $error) {
			throw new RuntimeException('Unable to read Nextcloud certificate revocation list: ' . $error->getMessage(), 0, $error);
		}
	}

	/**
	 * The list kept from an earlier fetch, or null when there is none or it cannot be read.
	 */
	private function stored(): ?CrlDocument {
		try {
			return CrlDocument::parse($this->appData->read(self::FOLDER . '/' . self::FILE));
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * The body the address answers with.
	 *
	 * @throws RuntimeException on an answer other than 200
	 */
	private function fetch(string $url): string {
		$response = $this->clientService->newClient()->get($url, ['timeout' => self::FETCH_TIMEOUT_SECONDS]);
		if ($response->getStatusCode() !== 200) {
			throw new RuntimeException('HTTP ' . $response->getStatusCode());
		}

		return (string)$response->getBody();
	}

	/**
	 * @throws RuntimeException with $failure when the file cannot be read
	 */
	private function readShipped(string $file, string $failure): string {
		$path = \OC::$SERVERROOT . '/resources/codesigning/' . $file;
		$content = is_readable($path) ? file_get_contents($path) : false;
		if ($content === false) {
			throw new RuntimeException($failure);
		}

		return $content;
	}
}
