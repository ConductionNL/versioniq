<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Alert;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * Posts one alert to one webhook target (releases-new-release-alerts, design
 * D6). A `json` target gets `{app, kind, subject, parameters, text, instance,
 * sentAt}`, a `chat` target `{text}`. With a secret the request carries
 * `X-Versioniq-Timestamp` and `X-Versioniq-Signature: sha256=<hex HMAC-SHA256
 * of "timestamp.body">`. A 2xx answer is success.
 *
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
 *
 * @psalm-type Alert = array{kind: string, subject: string, parameters: array<string, mixed>, text: string}
 */
class WebhookSender {
	public const TIMEOUT_SECONDS = 10;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Send the alert; the outcome and, on failure, why.
	 *
	 * @param array{url: string, format: string, secret: ?string} $target
	 * @param Alert $alert
	 * @return array{ok: bool, error: ?string}
	 */
	public function send(array $target, array $alert): array {
		$timestamp = (string)$this->time->getTime();
		$sent = $alert + ['instance' => $this->config->getSystemValueString('overwrite.cli.url', ''), 'sentAt' => gmdate(DATE_ATOM, (int)$timestamp)];
		$body = json_encode(self::body($target['format'], $sent), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		$headers = ['Content-Type' => 'application/json'];
		if ($target['secret'] !== null && $target['secret'] !== '') {
			$headers += ['X-Versioniq-Timestamp' => $timestamp, 'X-Versioniq-Signature' => self::signature($timestamp . '.' . $body, $target['secret'])];
		}

		try {
			$response = $this->clientService->newClient()->post($target['url'], [
				'body' => $body,
				'headers' => $headers,
				'timeout' => self::TIMEOUT_SECONDS,
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)],
			]);
		} catch (\Throwable $error) {
			return ['ok' => false, 'error' => $error->getMessage()];
		}

		return self::outcome($response->getStatusCode());
	}

	/**
	 * A 2xx answer is success.
	 *
	 * @return array{ok: bool, error: ?string}
	 */
	private static function outcome(int $status): array {
		return $status >= 200 && $status < 300
			? ['ok' => true, 'error' => null]
			: ['ok' => false, 'error' => 'The target answered HTTP ' . $status . '.'];
	}

	/**
	 * The request body for a format.
	 *
	 * @param array{kind: string, subject: string, parameters: array<string, mixed>, text: string, instance: string, sentAt: string} $alert
	 * @return array<string, mixed>
	 */
	public static function body(string $format, array $alert): array {
		if ($format === 'chat') {
			return ['text' => $alert['text']];
		}

		return ['app' => 'versioniq'] + array_intersect_key($alert, array_flip(['kind', 'subject', 'parameters', 'text', 'instance', 'sentAt']));
	}

	/**
	 * The `sha256=` signature of the signed string (`timestamp.body`) with the shared secret.
	 */
	public static function signature(string $signed, string $secret): string {
		return 'sha256=' . hash_hmac('sha256', $signed, $secret);
	}
}
