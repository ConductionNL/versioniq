<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/releases-channel-and-prereleases/specs/release-channels/spec.md#requirement-an-admin-sets-a-release-channel-for-the-instance-and-per-app
 */

namespace OCA\Versioniq\Tests\Unit\Controller;

use OCA\Versioniq\Controller\ChannelController;
use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Channel\ChannelAuditor;
use OCA\Versioniq\Service\Channel\ChannelStore;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The channel endpoints: admin-only, an unknown channel or line refused, one audit row per change.
 */
final class ChannelControllerTest extends TestCase {
	/** @var array<string, string> */
	private array $config = [];

	/** @var list<array{actor: string, appId: string, operation: string, status: string, message: ?string}> */
	private array $audits = [];

	private function controller(bool $admin = true): ChannelController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			},
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->config[$key]);
			},
		);
		$appConfig->method('getKeys')->willReturnCallback(fn (string $app): array => array_keys($this->config));

		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(
			function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
				$this->audits[] = ['actor' => $subject->actorUid, 'appId' => $subject->appId, 'operation' => $subject->operation, 'status' => $status, 'message' => $message];
			},
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('beheerder')->willReturn($admin);

		return new ChannelController('versioniq', $this->createMock(IRequest::class), new ChannelStore($appConfig), new ChannelAuditor($audit, $session), $groups, $session);
	}

	public function testANonAdminIsRefusedEverywhereAndNothingChanges(): void {
		$controller = $this->controller(admin: false);

		self::assertSame(403, $controller->channels()->getStatus());
		self::assertSame(403, $controller->setDefault('beta')->getStatus());
		self::assertSame(403, $controller->setAppChannel('hermiq', 'beta')->getStatus());
		self::assertSame(403, $controller->clearAppChannel('hermiq')->getStatus());
		self::assertSame(403, $controller->setLtsLines('hermiq', ['2.3'])->getStatus());
		self::assertSame(['message' => 'Forbidden'], $controller->channels()->getData());
		self::assertSame([], $this->config);
		self::assertSame([], $this->audits);
	}

	public function testTheListingHoldsTheDefaultEveryAppChannelAndEveryAppsLines(): void {
		$this->config = [
			'channel.default' => 'stable',
			'channel.hermiq' => 'beta',
			'channel.broken' => 'nightly',
			'lts_lines.hermiq' => '["2.3","2.4"]',
			'lts_lines.empty' => '[]',
			'auto_update.policy' => 'all',
		];

		$response = $this->controller()->channels();

		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'channels' => ['server', 'stable', 'beta', 'lts'],
			'default' => 'stable',
			'apps' => ['hermiq' => 'beta'],
			'ltsLines' => ['hermiq' => ['2.3', '2.4']],
		], $response->getData());
	}

	public function testSettingTheDefaultIsAuditedWithTheOldAndNewValue(): void {
		$response = $this->controller()->setDefault('beta');

		self::assertSame(200, $response->getStatus());
		self::assertSame('beta', $response->getData()['default']);
		self::assertSame('beta', $this->config['channel.default']);
		self::assertSame([['actor' => 'beheerder', 'appId' => 'versioniq', 'operation' => 'settings', 'status' => 'success', 'message' => 'Default release channel changed from server to beta']], $this->audits);
	}

	public function testAnUnknownChannelIsRefusedWithoutAChangeOrAnAuditRow(): void {
		$controller = $this->controller();

		$default = $controller->setDefault('nightly');
		$app = $controller->setAppChannel('hermiq', '');

		self::assertSame(400, $default->getStatus());
		self::assertSame(['message' => 'Unknown release channel: nightly'], $default->getData());
		self::assertSame(400, $app->getStatus());
		self::assertSame([], $this->config);
		self::assertSame([], $this->audits);
	}

	public function testAnAppsChannelIsSetAndClearedWithOneAuditRowEach(): void {
		$controller = $this->controller();

		$set = $controller->setAppChannel('hermiq', 'beta');
		self::assertSame(['hermiq' => 'beta'], $set->getData()['apps']);
		$cleared = $controller->clearAppChannel('hermiq');
		self::assertSame([], $cleared->getData()['apps']);
		$controller->clearAppChannel('hermiq');

		self::assertSame([
			['actor' => 'beheerder', 'appId' => 'hermiq', 'operation' => 'settings', 'status' => 'success', 'message' => 'Release channel changed from default to beta'],
			['actor' => 'beheerder', 'appId' => 'hermiq', 'operation' => 'settings', 'status' => 'success', 'message' => 'Release channel changed from beta to default'],
		], $this->audits, 'clearing a channel the app does not have records nothing');
	}

	public function testAMalformedAppIdIsRefused(): void {
		$controller = $this->controller();

		foreach (['default', 'Hermiq', '../x', str_repeat('a', 41)] as $appId) {
			self::assertSame(400, $controller->setAppChannel($appId, 'beta')->getStatus(), $appId);
			self::assertSame(400, $controller->clearAppChannel($appId)->getStatus(), $appId);
			self::assertSame(400, $controller->setLtsLines($appId, ['2.3'])->getStatus(), $appId);
		}
		self::assertSame([], $this->config);
		self::assertSame([], $this->audits);
	}

	public function testLtsLinesAreSetFromAListOrText(): void {
		$controller = $this->controller();

		$listed = $controller->setLtsLines('hermiq', ['2.4', ' 2.3', '2.4']);
		self::assertSame(['hermiq' => ['2.4', '2.3']], $listed->getData()['ltsLines']);
		$text = $controller->setLtsLines('hermiq', '3');
		self::assertSame(['hermiq' => ['3']], $text->getData()['ltsLines']);
		$cleared = $controller->setLtsLines('hermiq', '');
		self::assertSame([], $cleared->getData()['ltsLines']);

		self::assertSame([
			'Long-term support lines changed from [] to [2.4, 2.3]',
			'Long-term support lines changed from [2.4, 2.3] to [3]',
			'Long-term support lines changed from [3] to []',
		], array_column($this->audits, 'message'));
	}

	public function testABadLineIsRefusedWithoutAChange(): void {
		$this->config['lts_lines.hermiq'] = '["2.3"]';

		$response = $this->controller()->setLtsLines('hermiq', ['2.3', 'latest']);

		self::assertSame(400, $response->getStatus());
		self::assertSame(['message' => 'A long-term support line is a major or major.minor version.'], $response->getData());
		self::assertSame('["2.3"]', $this->config['lts_lines.hermiq']);
		self::assertSame([], $this->audits);
	}

	public function testEveryChangeAsksForThePassword(): void {
		foreach (['setDefault', 'setAppChannel', 'clearAppChannel', 'setLtsLines'] as $method) {
			$attributes = (new \ReflectionMethod(ChannelController::class, $method))->getAttributes(PasswordConfirmationRequired::class);
			self::assertCount(1, $attributes, $method);
		}
		self::assertCount(0, (new \ReflectionMethod(ChannelController::class, 'channels'))->getAttributes(PasswordConfirmationRequired::class));
	}
}
