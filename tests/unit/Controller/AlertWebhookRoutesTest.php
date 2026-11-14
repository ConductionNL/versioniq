<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\AlertController;
use OCA\Versioniq\Service\Alert\AlertServices;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseAlertSettings;
use OCA\Versioniq\Service\ReleaseAlert\ReleaseEventLog;
use OCA\Versioniq\Service\Alert\WebhookSender;
use OCA\Versioniq\Service\Alert\WebhookTargets;
use OCA\Versioniq\Service\Alert\WebhookTargetStore;
use OCA\Versioniq\Service\Api\ApiCaller;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

/**
 * The webhook routes: admin-only, https only, the secret stored encrypted and
 * never returned, one audit row per change, and Send test recording the outcome.
 *
 * @covers \OCA\Versioniq\Controller\AlertController
 * @covers \OCA\Versioniq\Service\Alert\WebhookTargetStore
 * @covers \OCA\Versioniq\Service\Alert\WebhookTargetInput
 * @covers \OCA\Versioniq\Service\Alert\WebhookTargets
 * @spec openspec/changes/releases-new-release-alerts/specs/alert-webhooks/spec.md#requirement-an-admin-manages-webhook-targets
 */
final class AlertWebhookRoutesTest extends TestCase {
	/** @var array<string, string> */
	private array $stored = [];

	/** @var list<array{0: AuditSubject, 1: ?string}> */
	private array $audited = [];

	/** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> */
	private array $sent = [];

	/** @var array{ok: bool, error: ?string} */
	private array $sendResult = ['ok' => true, 'error' => null];

	private function controller(array $body = [], bool $admin = true): AlertController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->stored[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;

			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->stored[$key]);
		});
		$config->method('getKeys')->willReturnCallback(fn (): array => array_keys($this->stored));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . strrev($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => strrev(substr($cipher, 4)));
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, $versions, string $status, ?string $message = null): void {
			$this->audited[] = [$subject, $message];
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T10:00:00+00:00'));
		$sender = $this->createMock(WebhookSender::class);
		$sender->method('send')->willReturnCallback(function (array $target, array $alert): array {
			$this->sent[] = [$target, $alert];

			return $this->sendResult;
		});
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new AlertController(
			'versioniq',
			$request,
			new AlertServices(
				new ReleaseAlertSettings($config, new ReleaseEventLog($config), $audit),
				new WebhookTargets(new WebhookTargetStore($config, $crypto, $audit, $time), $sender),
			),
			new ApiCaller($session, $groups, $time),
		);
	}

	private const MATTERMOST = ['name' => 'Ops channel', 'url' => 'https://chat.example.org/hooks/abc', 'format' => 'chat', 'kinds' => ['security', 'auto_update'], 'secret' => 's3cret', 'enabled' => true, 'ignored' => 'x'];

	public function testANonAdminIsRefusedEverywhereAndNothingIsStored(): void {
		$controller = $this->controller(self::MATTERMOST, false);

		foreach ([$controller->webhooks(), $controller->createWebhook(), $controller->updateWebhook('abc'), $controller->deleteWebhook('abc'), $controller->testWebhook('abc')] as $response) {
			self::assertSame(403, $response->getStatus());
		}
		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
		self::assertSame([], $this->sent);
	}

	public function testAnAdminAddsAChatTargetAndTheSecretIsEncryptedAndNeverReturned(): void {
		$response = $this->controller(self::MATTERMOST)->createWebhook();

		self::assertSame(200, $response->getStatus());
		$target = $response->getData()['target'];
		self::assertSame(['name' => 'Ops channel', 'url' => 'https://chat.example.org/hooks/abc', 'format' => 'chat', 'kinds' => ['security', 'auto_update'], 'enabled' => true, 'hasSecret' => true, 'last' => null], array_diff_key($target, ['id' => true]));
		$stored = json_decode($this->stored[WebhookTargetStore::PREFIX . $target['id']], true);
		self::assertSame('enc:terc3s', $stored['encryptedSecret']);
		self::assertArrayNotHasKey('ignored', $stored);
		foreach ([$response, $this->controller()->webhooks()] as $read) {
			self::assertStringNotContainsString('s3cret', json_encode($read->getData()));
			self::assertStringNotContainsString('terc3s', json_encode($read->getData()));
		}
		self::assertCount(1, $this->audited);
		self::assertSame(AuditLogger::OPERATION_SETTINGS, $this->audited[0][0]->operation);
	}

	public function testAnHttpAddressAndOtherBadFieldsAre400AndNothingIsStored(): void {
		foreach ([
			['url' => 'http://chat.example.org/hooks/abc'],
			['url' => 'https://user:pw@chat.example.org/hooks'],
			['url' => 'https://chat.example.org/hooks?token=1'],
			['kinds' => []],
			['kinds' => ['everything']],
			['format' => 'xml'],
			['name' => ''],
			['enabled' => 'yes'],
		] as $override) {
			$response = $this->controller($override + self::MATTERMOST)->createWebhook();
			self::assertSame(400, $response->getStatus(), json_encode($override));
		}
		self::assertSame([], $this->stored);
		self::assertSame([], $this->audited);
	}

	public function testEditingKeepsTheSecretUnlessClearedAndRemovingDeletesTheLastDelivery(): void {
		$id = $this->controller(self::MATTERMOST)->createWebhook()->getData()['target']['id'];

		$edited = $this->controller(['name' => 'Ops', 'secret' => ''])->updateWebhook($id)->getData()['target'];
		self::assertSame('Ops', $edited['name']);
		self::assertTrue($edited['hasSecret']);
		self::assertFalse($this->controller(['clearSecret' => true])->updateWebhook($id)->getData()['target']['hasSecret']);
		self::assertSame(400, $this->controller(['url' => 'http://x.example.org'])->updateWebhook($id)->getStatus());
		self::assertSame(404, $this->controller(['name' => 'x'])->updateWebhook('nope')->getStatus());

		$this->controller()->testWebhook($id);
		self::assertArrayHasKey(WebhookTargetStore::PREFIX . $id . '.last', $this->stored);
		self::assertSame(200, $this->controller()->deleteWebhook($id)->getStatus());
		self::assertSame([], $this->stored);
		self::assertSame(404, $this->controller()->deleteWebhook($id)->getStatus());
		self::assertCount(4, $this->audited);
	}

	public function testSendTestPostsTheTestMessageWithTheSecretAndRecordsTheOutcome(): void {
		$id = $this->controller(self::MATTERMOST)->createWebhook()->getData()['target']['id'];

		$target = $this->controller()->testWebhook($id)->getData()['target'];
		self::assertSame(['at' => '2026-11-14T10:00:00+00:00', 'status' => 'succeeded', 'error' => null], $target['last']);
		self::assertSame('s3cret', $this->sent[0][0]['secret']);
		self::assertSame(WebhookTargets::TEST_ALERT, $this->sent[0][1]);

		$this->sendResult = ['ok' => false, 'error' => 'The target answered HTTP 404.'];
		self::assertSame(['at' => '2026-11-14T10:00:00+00:00', 'status' => 'failed', 'error' => 'The target answered HTTP 404.'], $this->controller()->testWebhook($id)->getData()['target']['last']);
		self::assertSame(404, $this->controller()->testWebhook('missing')->getStatus());
	}

	public function testTheWritesNeedThePassword(): void {
		foreach (['createWebhook', 'updateWebhook', 'deleteWebhook'] as $name) {
			self::assertCount(1, (new \ReflectionMethod(AlertController::class, $name))->getAttributes(PasswordConfirmationRequired::class), $name);
		}
	}

	public function testAnHttpTargetSetOutsideTheFormIsReadAndAMalformedOneIsSkipped(): void {
		$this->stored['webhook.fixture'] = '{"name": "Fixture", "url": "http://127.0.0.1:8090/hook", "format": "json", "kinds": ["new_release"], "enabled": true}';
		$this->stored['webhook.broken'] = '{"name": "Broken"}';

		$targets = $this->controller()->webhooks()->getData()['targets'];
		self::assertSame(['fixture'], array_column($targets, 'id'));
		self::assertSame('http://127.0.0.1:8090/hook', $targets[0]['url']);
	}
}
