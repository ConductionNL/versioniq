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
		$body = json_encode(self::body($target['format'], $alert, $this->instance(), $timestamp), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		try {
			$response = $this->clientService->newClient()->post($target['url'], [
				'body' => $body,
				'headers' => self::headers($body, $timestamp, $target['secret']),
				'timeout' => self::TIMEOUT_SECONDS,
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => $this->config->getSystemValueBool('allow_local_remote_servers', false)],
			]);
		} catch (\Throwable $error) {
			return ['ok' => false, 'error' => $error->getMessage()];
		}

		$status = $response->getStatusCode();

		return $status >= 200 && $status < 300
			? ['ok' => true, 'error' => null]
			: ['ok' => false, 'error' => 'The target answered HTTP ' . $status . '.'];
	}

	/**
	 * The request body for a format.
	 *
	 * @param Alert $alert
	 * @return array<string, mixed>
	 */
	public static function body(string $format, array $alert, string $instance, string $timestamp): array {
		if ($format === 'chat') {
			return ['text' => $alert['text']];
		}

		return [
			'app' => 'versioniq',
			'kind' => $alert['kind'],
			'subject' => $alert['subject'],
			'parameters' => $alert['parameters'],
			'text' => $alert['text'],
			'instance' => $instance,
			'sentAt' => gmdate(DATE_ATOM, (int)$timestamp),
		];
	}

	/**
	 * The `sha256=` signature of `timestamp.body` with the shared secret.
	 */
	public static function signature(string $timestamp, string $body, string $secret): string {
		return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
	}

	/**
	 * @return array<string, string>
	 */
	private static function headers(string $body, string $timestamp, ?string $secret): array {
		$headers = ['Content-Type' => 'application/json'];
		if ($secret !== null && $secret !== '') {
			$headers['X-Versioniq-Timestamp'] = $timestamp;
			$headers['X-Versioniq-Signature'] = self::signature($timestamp, $body, $secret);
		}

		return $headers;
	}

	private function instance(): string {
		return $this->config->getSystemValueString('overwrite.cli.url', '');
	}
}
