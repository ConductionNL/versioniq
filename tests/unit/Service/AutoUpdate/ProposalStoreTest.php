<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\AutoUpdate;

require_once __DIR__ . '/InMemoryAppConfig.php';

use OCA\Versioniq\Service\AutoUpdate\ProposalStore;
use OCA\Versioniq\Service\AutoUpdate\ProposedUpdate;
use OCA\Versioniq\Service\Settings\StoredSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ProposalStore::class)]
#[UsesClass(ProposedUpdate::class)]
#[UsesClass(StoredSnapshot::class)]
final class ProposalStoreTest extends TestCase {
	private InMemoryAppConfig $config;
	private LoggerInterface $logger;
	private ProposalStore $store;

	protected function setUp(): void {
		$this->config = new InMemoryAppConfig();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->store = new ProposalStore($this->config->mock($this, $this->createMock(...)), $this->logger);
	}

	public function testNothingStoredReadsAsNeverComputed(): void {
		$this->assertSame(['proposals' => [], 'reasons' => [], 'computedAt' => null], $this->store->read());
	}

	public function testASnapshotRoundTrips(): void {
		$proposal = $this->proposal('2.3.4', 'release notes');
		$this->store->save(['openregister' => $proposal], ['calendar' => 'pinned'], 1790000000);

		$read = $this->store->read();

		$this->assertSame(1790000000, $read['computedAt']);
		$this->assertSame(['calendar' => 'pinned'], $read['reasons']);
		$this->assertSame($proposal->toArray(), $read['proposals']['openregister']->toArray());
		$this->assertSame('2.3.4', $this->store->proposalFor('openregister')?->toVersion);
		$this->assertNull($this->store->proposalFor('calendar'));
	}

	public function testASnapshotThatCannotBeEncodedKeepsThePreviousOne(): void {
		$this->store->save(['openregister' => $this->proposal('2.3.4', 'fine')], [], 1790000000);
		$this->logger->expects($this->once())->method('error')
			->with('ProposalStore: could not encode the proposed updates; keeping the previous ones');

		// Invalid UTF-8 makes json_encode throw.
		$this->store->save(['openregister' => $this->proposal('2.3.5', "\xB1\x31")], [], 1790009999);

		$read = $this->store->read();
		$this->assertSame('2.3.4', $read['proposals']['openregister']->toVersion);
		$this->assertSame(1790000000, $read['computedAt']);
	}

	public function testReplaceKeepsTheOtherProposalsAndTheComputedTime(): void {
		$this->store->save([
			'openregister' => $this->proposal('2.3.4', ''),
			'deck' => new ProposedUpdate('deck', '1.9.0', '1.9.3', 'patch', ProposedUpdate::STATUS_PLANNED, '2026-07-01T00:00:00+00:00'),
		], ['calendar' => 'pinned'], 1790000000);

		$this->store->replace($this->proposal('2.3.4', '')->with(ProposedUpdate::STATUS_APPROVED, null, null, ['by' => 'alice', 'at' => '2026-07-02T00:00:00+00:00']));

		$read = $this->store->read();
		$this->assertSame(ProposedUpdate::STATUS_APPROVED, $read['proposals']['openregister']->status);
		$this->assertSame('1.9.3', $read['proposals']['deck']->toVersion);
		$this->assertSame(['calendar' => 'pinned'], $read['reasons']);
		$this->assertSame(1790000000, $read['computedAt']);
	}

	public function testAnApprovalCoversExactlyOneVersion(): void {
		$this->store->approve('openregister', '2.3.4', 'alice', '2026-07-02T00:00:00+00:00');

		$this->assertTrue($this->store->isApproved('openregister', '2.3.4'));
		$this->assertFalse($this->store->isApproved('openregister', '2.4.0'));
		$this->assertFalse($this->store->isApproved('deck', '2.3.4'));
		$this->assertSame(['version' => '2.3.4', 'by' => 'alice', 'at' => '2026-07-02T00:00:00+00:00'], $this->store->approval('openregister'));

		$this->store->approve('openregister', '2.3.5', 'bob', '2026-07-03T00:00:00+00:00');
		$this->assertFalse($this->store->isApproved('openregister', '2.3.4'));
		$this->assertTrue($this->store->isApproved('openregister', '2.3.5'));
	}

	public function testADeclineHoldsUntilWithdrawn(): void {
		$this->store->decline('deck', '1.9.3', 'alice', '2026-07-02T00:00:00+00:00', 'breaks our board import');

		$this->assertSame(['by' => 'alice', 'at' => '2026-07-02T00:00:00+00:00', 'reason' => 'breaks our board import'], $this->store->declineOf('deck', '1.9.3'));
		$this->assertNull($this->store->declineOf('deck', '1.9.4'));

		$this->assertTrue($this->store->withdrawDecline('deck', '1.9.3'));
		$this->assertNull($this->store->declineOf('deck', '1.9.3'));
		$this->assertFalse($this->store->withdrawDecline('deck', '1.9.3'));
	}

	public function testDecisionsSurviveANewSnapshot(): void {
		$this->store->approve('openregister', '2.3.4', 'alice', '2026-07-02T00:00:00+00:00');
		$this->store->decline('deck', '1.9.3', 'alice', '2026-07-02T00:00:00+00:00', '');

		$this->store->save([], [], 1790000000);

		$this->assertTrue($this->store->isApproved('openregister', '2.3.4'));
		$this->assertNotNull($this->store->declineOf('deck', '1.9.3'));
	}

	public function testMalformedStoredValuesReadAsNothing(): void {
		$this->config->values[ProposalStore::KEY] = '{not json';
		$this->config->values[ProposalStore::DECISIONS_KEY] = 'nope';

		$this->assertSame([], $this->store->read()['proposals']);
		$this->assertNull($this->store->approval('openregister'));
	}

	public function testAMalformedStoredProposalIsSkippedAndTheRestRead(): void {
		$this->config->values[ProposalStore::KEY] = json_encode(['proposals' => [
			'broken' => ['appId' => 'broken'],
			'openregister' => $this->proposal('2.3.4', '')->toArray(),
		], 'reasons' => ['calendar' => 'pinned', 'odd' => 3]]);
		$this->logger->expects($this->once())->method('warning')
			->with('ProposalStore: skipping a malformed stored proposal', $this->arrayHasKey('appId'));

		$read = $this->store->read();

		$this->assertSame(['openregister'], array_keys($read['proposals']));
		$this->assertSame(['calendar' => 'pinned'], $read['reasons']);
	}

	private function proposal(string $toVersion, string $notes): ProposedUpdate {
		return new ProposedUpdate('openregister', '2.3.0', $toVersion, 'patch', ProposedUpdate::STATUS_PLANNED, '2026-07-01T00:00:00+00:00', '2026-07-02T01:00:00+00:00', null, $notes);
	}
}
