<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Audit;

use OCA\Versioniq\Service\Audit\TriggerDescriber;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Versioniq\Service\Audit\TriggerDescriber
 */
final class TriggerDescriberTest extends TestCase {
	public function testAnOccCommandIsNamedWithItsArguments(): void {
		$this->assertSame('occ app:update openregister', TriggerDescriber::describeCommand(['/var/www/html/occ', 'app:update', 'openregister']));
	}

	public function testPasswordAndTokenValuesAreRedacted(): void {
		$this->assertSame(
			'occ user:add --password [redacted] --display-name Bob bob',
			TriggerDescriber::describeCommand(['occ', 'user:add', '--password', 's3cret', '--display-name', 'Bob', 'bob']),
		);
		$this->assertSame(
			'occ x --token=[redacted] --pass [redacted] --PASSWORD=[redacted]',
			TriggerDescriber::describeCommand(['occ', 'x', '--token=abc', '--pass', 'def', '--PASSWORD=ghi']),
		);
	}

	public function testALongCommandIsCutAtTwoHundredCharacters(): void {
		$described = TriggerDescriber::describeCommand(['occ', 'app:update', str_repeat('a', 300)]);

		$this->assertSame(200, mb_strlen($described));
		$this->assertStringStartsWith('occ app:update aaa', $described);
	}

	public function testAWebRequestIsItsMethodAndPathWithoutTheQuery(): void {
		$this->assertSame('POST /settings/apps/update/calendar', TriggerDescriber::describeRequest('post', '/settings/apps/update/calendar?token=abc&x=1'));
	}

	public function testUnknownWhenThereIsNothingToDescribe(): void {
		$this->assertSame('unknown', TriggerDescriber::describeCommand([]));
		$this->assertSame('unknown', TriggerDescriber::describeRequest('', '/x'));
		$this->assertSame('unknown', TriggerDescriber::describeRequest('GET', '?only=query'));
	}

	public function testTheCurrentProcessIsDescribedAsItsCommandLine(): void {
		$request = $this->createMock(IRequest::class);
		$request->expects($this->never())->method('getRequestUri');
		$previous = $_SERVER['argv'] ?? null;
		$_SERVER['argv'] = ['/srv/nextcloud/occ', 'app:disable', 'calendar'];
		try {
			$this->assertSame('occ app:disable calendar', (new TriggerDescriber($request))->describe());
		} finally {
			$_SERVER['argv'] = $previous;
		}
	}
}
