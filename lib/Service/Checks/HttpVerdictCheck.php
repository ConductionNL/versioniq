<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Checks;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * Runs one verdict check: a GET to the check's address, with `{appId}`,
 * `{version}` and `{fromVersion}` URL-encoded into it, that passes only on a
 * 2xx answer within {@see UpdateCheck::VERDICT_TIMEOUT} seconds.
 *
 * The address must be https. The one exception is an instance that allows
 * local remote servers (`allow_local_remote_servers`, a test and development
 * switch), where an http address set through `occ config:app:set` is called,
 * but never with the token. Redirects are not followed, so the token never
 * leaves for another address; a 3xx is not a pass. Local addresses are
 * blocked unless `allow_local_remote_servers` is on, as for every other
 * outbound call of this app.
 *
 * @psalm-api
 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
 */
class HttpVerdictCheck {
	/** Characters of an error message kept in the result. */
	private const MAX_DETAIL_LENGTH = 500;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @return array{passed: bool, detail: string}
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public function run(UpdateCheck $check, CheckSubject $subject): array {
		$url = self::addressFor($check, $subject);
		$allowLocal = $this->config->getSystemValueBool('allow_local_remote_servers', false);
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		$https = $scheme === 'https';
		if (!$https && !($scheme === 'http' && $allowLocal)) {
			return ['passed' => false, 'detail' => 'The address is not https; it was not called.'];
		}

		$options = [
			'timeout' => UpdateCheck::VERDICT_TIMEOUT,
			'connect_timeout' => UpdateCheck::VERDICT_TIMEOUT,
			'http_errors' => false,
			'allow_redirects' => false,
			'headers' => ['User-Agent' => 'Nextcloud-Versioniq', 'Accept' => 'application/json, text/plain, */*'],
			'nextcloud' => ['allow_local_address' => $allowLocal],
		];
		try {
			if ($https && $check->hasToken()) {
				$options['headers']['Authorization'] = 'Bearer ' . $this->crypto->decrypt((string)$check->encryptedToken);
			}
			$status = $this->clientService->newClient()->get($url, $options)->getStatusCode();
		} catch (\Throwable $error) {
			return ['passed' => false, 'detail' => self::shortened('No answer: ' . $error->getMessage())];
		}

		return ['passed' => $status >= 200 && $status < 300, 'detail' => 'HTTP ' . $status];
	}

	/**
	 * The check's address with each placeholder URL-encoded.
	 *
	 * @spec openspec/changes/safety-update-checks/specs/update-checks/spec.md#requirement-admins-and-operators-attach-checks-that-every-dry-run-runs
	 */
	public static function addressFor(UpdateCheck $check, CheckSubject $subject): string {
		return strtr($check->url, $subject->placeholders(rawurlencode(...), false));
	}

	private static function shortened(string $detail): string {
		$detail = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $detail) ?? $detail;

		return mb_strlen($detail) > self::MAX_DETAIL_LENGTH ? mb_substr($detail, 0, self::MAX_DETAIL_LENGTH) . '…' : $detail;
	}
}
