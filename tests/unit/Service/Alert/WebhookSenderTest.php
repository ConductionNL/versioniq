<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Alert;

use OCA\Versioniq\Service\Alert\WebhookSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Both body formats, the signature against a vector computed outside PHP, and the 2xx rule.
 *
 * @covers \OCA\Versioniq\Service\Alert\WebhookSender
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-every-versioniq-alert-is-delivered-to-matching-targets-in-the-background
 */
final class WebhookSenderTest extends TestCase {
	private const ALERT = ['kind' => 'auto_update', 'subject' => 'auto_update_failure', 'parameters' => ['app' => 'openregister', 'targetVersion' => '2.3.4', 'hint' => 'disk full'], 'text' => 'Automatic update failed'];

	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $posts = [];

	private function sender(int|\Throwable $answer, bool $allowLocal = false): WebhookSender {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url, array $options) use ($answer): IResponse {
			$this->posts[] = [$url, $options];
			if ($answer instanceof \Throwable) {
				throw $answer;
			}
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($answer);

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(static fn (string $key, bool $default): bool => $key === 'allow_local_remote_servers' ? $allowLocal : $default);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default): string => $key === 'overwrite.cli.url' ? 'https://cloud.example.org' : $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_790_000_000);

		return new WebhookSender($clients, $config, $time);
	}

	public function testAJsonTargetGetsTheWholeAlertSignedWithTheSecret(): void {
		$result = $this->sender(204)->send(['url' => 'https://hooks.example.org/x', 'format' => 'json', 'secret' => 'shared-secret'], self::ALERT);

		self::assertSame(['ok' => true, 'error' => null], $result);
		[$url, $options] = $this->posts[0];
		self::assertSame('https://hooks.example.org/x', $url);
		self::assertSame(
			['app' => 'versioniq', 'kind' => 'auto_update', 'subject' => 'auto_update_failure', 'parameters' => self::ALERT['parameters'], 'text' => 'Automatic update failed', 'instance' => 'https://cloud.example.org', 'sentAt' => '2026-09-21T14:13:20+00:00'],
			json_decode($options['body'], true)
		);
		self::assertSame('1790000000', $options['headers']['X-Versioniq-Timestamp']);
		self::assertSame(WebhookSender::signature('1790000000.' . $options['body'], 'shared-secret'), $options['headers']['X-Versioniq-Signature']);
		self::assertSame(WebhookSender::TIMEOUT_SECONDS, $options['timeout']);
		self::assertSame(['allow_local_address' => false], $options['nextcloud']);
	}

	public function testTheSignatureMatchesAVectorComputedOutsidePhp(): void {
		self::assertSame(
			'sha256=2266598dcdd12004302b9fb589b518263fc53add1389d9d9b06eb767afc57eb8',
			WebhookSender::signature('1790000000.{"text":"Test message"}', 'shared-secret')
		);
	}

	public function testAChatTargetGetsOnlyTheTextAndNoSignatureWithoutASecret(): void {
		$this->sender(200, true)->send(['url' => 'https://chat.example.org/hooks/abc', 'format' => 'chat', 'secret' => null], self::ALERT);

		[, $options] = $this->posts[0];
		self::assertSame('{"text":"Automatic update failed"}', $options['body']);
		self::assertSame(['Content-Type' => 'application/json'], $options['headers']);
		self::assertSame(['allow_local_address' => true], $options['nextcloud']);
	}

	public function testANon2xxAnswerOrATransportErrorIsAFailure(): void {
		$target = ['url' => 'https://hooks.example.org/x', 'format' => 'json', 'secret' => null];

		self::assertSame(['ok' => false, 'error' => 'The target answered HTTP 500.'], $this->sender(500)->send($target, self::ALERT));
		self::assertSame(['ok' => false, 'error' => 'The target answered HTTP 302.'], $this->sender(302)->send($target, self::ALERT));
		self::assertSame(['ok' => false, 'error' => 'Connection timed out'], $this->sender(new \RuntimeException('Connection timed out'))->send($target, self::ALERT));
	}
}
