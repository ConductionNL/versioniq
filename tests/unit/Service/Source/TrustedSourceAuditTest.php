<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Audit\AuditedVersions;
use OCA\Versioniq\Service\Audit\AuditLogger;
use OCA\Versioniq\Service\Audit\AuditSubject;
use OCA\Versioniq\Service\Source\TrustedSourceList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Trusting and untrusting a source is audited once per real change, with the
 * pattern as the message (sources-trust-audit-and-offline-feed 1.1).
 *
 * @covers \OCA\Versioniq\Service\Source\TrustedSourceList
 *
 * @spec openspec/changes/sources-trust-audit-and-offline-feed/specs/audit-trail/spec.md
 */
final class TrustedSourceAuditTest extends TestCase {
	private string $stored = '';

	/** @var list<array{0: AuditSubject, 1: AuditedVersions, 2: string, 3: ?string}> */
	private array $rows = [];

	public function testTrustingAndUntrustingArePatternRowsByTheAdmin(): void {
		$list = $this->list(uid: 'alice');

		$this->assertSame(['github:ConductionNL/*', 'github:acme/*'], $list->trust('github:acme/*'));
		$this->assertSame(['github:ConductionNL/*'], $list->untrust('github:acme/*'));

		$this->assertSame(
			[
				['alice', 'versioniq', 'trust_source', 'github:acme/*'],
				['alice', 'versioniq', 'untrust_source', 'github:acme/*'],
			],
			array_map(static fn (array $row): array => [$row[0]->actorUid, $row[0]->appId, $row[0]->operation, $row[3]], $this->rows)
		);
		$this->assertSame(AuditLogger::STATUS_SUCCESS, $this->rows[0][2]);
	}

	public function testNothingChangedIsNotAudited(): void {
		$list = $this->list(uid: 'alice');

		$list->trust('github:ConductionNL/*');
		$list->untrust('github:never/trusted');

		$this->assertSame([], $this->rows);
	}

	public function testWithoutASessionTheActorIsSystem(): void {
		$list = $this->list(uid: null);

		$list->trust('codeberg:acme/tool');

		$this->assertSame('system', $this->rows[0][0]->actorUid);
	}

	public function testWithoutAnAuditLoggerTheListStillWorks(): void {
		$list = new TrustedSourceList($this->config());

		$this->assertSame(['github:ConductionNL/*', 'forgejo:acme/*'], $list->trust('forgejo:acme/*'));
	}

	private function list(?string $uid): TrustedSourceList {
		$audit = $this->createMock(AuditLogger::class);
		$audit->method('record')->willReturnCallback(function (AuditSubject $subject, AuditedVersions $versions, string $status, ?string $message = null): void {
			$this->rows[] = [$subject, $versions, $status, $message];
		});
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session->method('getUser')->willReturn($user);

		return new TrustedSourceList($this->config(), $audit, $session);
	}

	private function config(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored = $value;
			return true;
		});

		return $config;
	}
}
