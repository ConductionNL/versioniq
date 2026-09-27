<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\Versioniq\Service\Source\SourceBinding;
use PHPUnit\Framework\TestCase;

final class SourceBindingTest extends TestCase {
	public function testAppStoreFactoryProducesAppstoreId(): void {
		$binding = SourceBinding::appStore();

		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
		$this->assertSame('appstore', $binding->getId());
		$this->assertNull($binding->getOwnerRepo());
	}

	public function testGithubFactoryProducesNamespacedId(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister');

		$this->assertSame(SourceBinding::KIND_GITHUB_RELEASE, $binding->kind);
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
		$this->assertSame('ConductionNL/openregister', $binding->getOwnerRepo());
		$this->assertSame('*.tar.gz', $binding->getAssetPattern());
		$this->assertNotNull($binding->boundAt);
	}

	public function testGithubFactoryAcceptsCustomAssetPattern(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister', 'openregister-*.zip');

		$this->assertSame('openregister-*.zip', $binding->getAssetPattern());
	}

	public function testRoundtripThroughArray(): void {
		$original = SourceBinding::github('ConductionNL', 'openregister');
		$restored = SourceBinding::fromArray($original->toArray());

		$this->assertSame($original->getId(), $restored->getId());
		$this->assertSame($original->getAssetPattern(), $restored->getAssetPattern());
		$this->assertSame($original->boundAt, $restored->boundAt);
	}

	public function testFromArrayRejectsMissingKind(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceBinding::fromArray(['owner' => 'foo', 'repo' => 'bar']);
	}

	public function testGithubBindingRequiresOwnerAndRepo(): void {
		$this->expectException(InvalidArgumentException::class);

		new SourceBinding(SourceBinding::KIND_GITHUB_RELEASE, ['owner' => 'foo']);
	}

	public function testUnknownKindRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		new SourceBinding('mystery-source');
	}

	public function testAssetPatternFallsBackOnInvalidConfig(): void {
		$binding = new SourceBinding(
			SourceBinding::KIND_GITHUB_RELEASE,
			['owner' => 'a', 'repo' => 'b', 'assetPattern' => '']
		);

		$this->assertSame('*.tar.gz', $binding->getAssetPattern());
	}

	public function testCodebergFactoryIdAndForge(): void {
		$binding = SourceBinding::codeberg('Conduction', 'pipelinq');

		$this->assertSame(SourceBinding::KIND_GITHUB_RELEASE, $binding->kind);
		$this->assertSame('codeberg', $binding->getForge());
		$this->assertSame('codeberg:Conduction/pipelinq', $binding->getId());
	}

	public function testGithubIdAndForgeUnchanged(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister');

		$this->assertSame('github', $binding->getForge());
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
	}

	public function testLegacyBindingWithoutForgeDefaultsToGithub(): void {
		// A persisted pre-forge row has no `forge` key.
		$binding = SourceBinding::fromArray([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
		]);

		$this->assertSame('github', $binding->getForge());
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
	}

	public function testUnknownForgeRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceBinding::fromArray([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'forge' => 'gitlab',
			'owner' => 'a',
			'repo' => 'b',
		]);
	}

	// --- Recorded SHA-256 (TOFU) — "SHA-256 recorded on first successful
	// external install" / "Recorded digests are binding-scoped and surfaced" ---

	private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function testGetRecordedShaIsNullWhenNoneRecorded(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister');

		$this->assertNull($binding->getRecordedSha('2.5.0'));
	}

	public function testWithRecordedShaIsImmutableAndReadableBack(): void {
		$original = SourceBinding::github('ConductionNL', 'openregister');
		$updated = $original->withRecordedSha('2.5.0', self::SHA_A);

		$this->assertNull($original->getRecordedSha('2.5.0'), 'original binding must be unchanged');
		$this->assertSame(self::SHA_A, $updated->getRecordedSha('2.5.0'));
	}

	public function testWithRecordedShaLowercasesTheDigest(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister')
			->withRecordedSha('2.5.0', strtoupper(self::SHA_A));

		$this->assertSame(self::SHA_A, $binding->getRecordedSha('2.5.0'));
	}

	public function testWithRecordedShaRejectsAMalformedDigest(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceBinding::github('ConductionNL', 'openregister')->withRecordedSha('2.5.0', 'not-a-digest');
	}

	public function testWithRecordedShaRejectsAnEmptyVersion(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceBinding::github('ConductionNL', 'openregister')->withRecordedSha('', self::SHA_A);
	}

	public function testRecordedShaRoundtripsThroughArray(): void {
		$original = SourceBinding::github('ConductionNL', 'openregister')
			->withRecordedSha('2.5.0', self::SHA_A)
			->withRecordedSha('2.3.0', self::SHA_B);
		$restored = SourceBinding::fromArray($original->toArray());

		$this->assertSame(self::SHA_A, $restored->getRecordedSha('2.5.0'));
		$this->assertSame(self::SHA_B, $restored->getRecordedSha('2.3.0'));
		$this->assertSame(['2.5.0' => self::SHA_A, '2.3.0' => self::SHA_B], $restored->getRecordedShaMap());
	}

	public function testToArrayOmitsEmptyShaMap(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister');

		$this->assertArrayNotHasKey('sha256', $binding->toArray());
	}

	public function testFromArrayDropsInvalidShaEntries(): void {
		$binding = SourceBinding::fromArray([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
			'sha256' => [
				'2.5.0' => self::SHA_A,
				'2.4.0' => 'too-short',
				'' => self::SHA_B, // empty version key
				'2.2.0' => 12345, // non-string digest
			],
		]);

		$this->assertSame(self::SHA_A, $binding->getRecordedSha('2.5.0'));
		$this->assertNull($binding->getRecordedSha('2.4.0'));
		$this->assertNull($binding->getRecordedSha('2.2.0'));
		$this->assertSame(['2.5.0' => self::SHA_A], $binding->getRecordedShaMap());
	}

	public function testWithRecordedShaCapsAtTwoHundredEntriesEvictingOldestFirst(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister');
		for ($i = 1; $i <= SourceBinding::MAX_RECORDED_SHA + 1; $i++) {
			$binding = $binding->withRecordedSha('1.0.' . $i, str_pad(dechex($i), 64, '0', STR_PAD_LEFT));
		}

		$map = $binding->getRecordedShaMap();
		$this->assertCount(SourceBinding::MAX_RECORDED_SHA, $map);
		// The very first entry (version 1.0.1) must have been evicted.
		$this->assertNull($binding->getRecordedSha('1.0.1'));
		// The most recent entry survives.
		$this->assertNotNull($binding->getRecordedSha('1.0.' . (SourceBinding::MAX_RECORDED_SHA + 1)));
	}

	public function testWithRecordedShaOverwritesAnExistingVersionWithoutGrowingTheMap(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister')
			->withRecordedSha('2.5.0', self::SHA_A)
			->withRecordedSha('2.5.0', self::SHA_B);

		$this->assertSame(self::SHA_B, $binding->getRecordedSha('2.5.0'));
		$this->assertCount(1, $binding->getRecordedShaMap());
	}

	public function testWithRecordedShaMapCarriesDigestsAndDropsInvalidEntries(): void {
		// A fresh override binding takes on a stored binding's recorded digests,
		// so a same-source override still enforces trust-on-first-use.
		$fresh = SourceBinding::github('ConductionNL', 'openregister');
		$carried = $fresh->withRecordedShaMap([
			'2.3.0' => self::SHA_A,
			'2.4.0' => self::SHA_B,
			'' => self::SHA_A,        // empty version dropped
			'2.5.0' => 'not-a-digest', // invalid digest dropped
		]);

		$this->assertSame(self::SHA_A, $carried->getRecordedSha('2.3.0'));
		$this->assertSame(self::SHA_B, $carried->getRecordedSha('2.4.0'));
		$this->assertNull($carried->getRecordedSha('2.5.0'));
		$this->assertCount(2, $carried->getRecordedShaMap());
		// The original is untouched (immutability).
		$this->assertCount(0, $fresh->getRecordedShaMap());
	}

	public function testForgejoForgeIsAccepted(): void {
		$binding = new SourceBinding(SourceBinding::KIND_GITHUB_RELEASE, [
			'forge' => 'forgejo',
			'owner' => 'acme',
			'repo' => 'widget',
		]);

		$this->assertSame('forgejo', $binding->getForge());
		$this->assertSame('forgejo:acme/widget', $binding->getId());
	}
}
