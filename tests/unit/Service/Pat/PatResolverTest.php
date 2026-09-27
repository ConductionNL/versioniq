<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Pat;

use OCA\Versioniq\Db\Pat;
use OCA\Versioniq\Db\PatMapper;
use OCA\Versioniq\Service\Pat\PatResolver;
use PHPUnit\Framework\TestCase;

final class PatResolverTest extends TestCase {
	private function makePat(int $id, string $owner, string $pattern, ?string $expiresAt = null): Pat {
		$pat = new Pat();
		$pat->setId($id);
		$pat->setOwnerUid($owner);
		$pat->setTargetPattern($pattern);
		$pat->setExpiresAt($expiresAt);

		return $pat;
	}

	private function buildResolver(array $patsVisibleTo): PatResolver {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findVisibleTo')->willReturnCallback(
			static fn (string $uid): array => $patsVisibleTo[$uid] ?? []
		);

		return new PatResolver($mapper);
	}

	public function testReturnsNullWhenNoPatsVisible(): void {
		$resolver = $this->buildResolver(['admin' => []]);

		$this->assertNull($resolver->findFor('github', 'ConductionNL/openregister', 'admin'));
	}

	public function testMatchesGlobPattern(): void {
		$pat = $this->makePat(1, 'admin', 'ConductionNL/*');
		$resolver = $this->buildResolver(['admin' => [$pat]]);

		$found = $resolver->findFor('github', 'ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(1, $found->getId());
	}

	public function testNonMatchingPatternReturnsNull(): void {
		$pat = $this->makePat(1, 'admin', 'OtherOrg/*');
		$resolver = $this->buildResolver(['admin' => [$pat]]);

		$this->assertNull($resolver->findFor('github', 'ConductionNL/openregister', 'admin'));
	}

	public function testExpiredPatSkipped(): void {
		$expired = $this->makePat(1, 'admin', 'ConductionNL/*', '2020-01-01 00:00:00');
		$valid = $this->makePat(2, 'admin', 'ConductionNL/*', '2099-01-01 00:00:00');

		$resolver = $this->buildResolver(['admin' => [$expired, $valid]]);

		$found = $resolver->findFor('github', 'ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId());
	}

	public function testOwnerOwnedPreferredOverShared(): void {
		$shared = $this->makePat(1, 'other', 'ConductionNL/*');
		$shared->setSharedWithAdmins(true);
		$ownerOwned = $this->makePat(2, 'admin', 'ConductionNL/*');

		$resolver = $this->buildResolver(['admin' => [$shared, $ownerOwned]]);

		$found = $resolver->findFor('github', 'ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId(), 'PAT owned by current user should win over shared one');
	}

	public function testMoreSpecificPatternPreferred(): void {
		$broad = $this->makePat(1, 'admin', 'ConductionNL/*');
		$specific = $this->makePat(2, 'admin', 'ConductionNL/openregister');

		$resolver = $this->buildResolver(['admin' => [$broad, $specific]]);

		$found = $resolver->findFor('github', 'ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId());
	}

	public function testForgeScopedMatching(): void {
		$githubPat = $this->makePat(1, 'admin', 'Conduction/*'); // forge defaults to github
		$codebergPat = $this->makePat(2, 'admin', 'Conduction/*');
		$codebergPat->setForge('codeberg');

		$resolver = $this->buildResolver(['admin' => [$githubPat, $codebergPat]]);

		// A codeberg binding only matches the codeberg token, and github only github.
		$this->assertSame(2, $resolver->findFor('codeberg', 'Conduction/pipelinq', 'admin')?->getId());
		$this->assertSame(1, $resolver->findFor('github', 'Conduction/pipelinq', 'admin')?->getId());
	}

	public function testLegacyGithubPatNeverMatchesCodeberg(): void {
		$legacy = $this->makePat(1, 'admin', 'ConductionNL/*'); // legacy row → forge defaults github

		$resolver = $this->buildResolver(['admin' => [$legacy]]);

		$this->assertSame(1, $resolver->findFor('github', 'ConductionNL/openregister', 'admin')?->getId());
		$this->assertNull($resolver->findFor('codeberg', 'ConductionNL/openregister', 'admin'));
	}

	/**
	 * A background job runs without a session user. It must still find a
	 * token, and only one an admin has shared: a private token is never used
	 * on behalf of nobody (#430).
	 */
	public function testNoUserResolvesSharedTokensOnly(): void {
		$private = $this->makePat(1, 'alice', 'ConductionNL/private-app');
		$shared = $this->makePat(2, 'bob', 'ConductionNL/*');
		$shared->setSharedWithAdmins(true);

		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$private, $shared]);
		$mapper->expects($this->never())->method('findVisibleTo');

		$found = (new PatResolver($mapper))->findFor('github', 'ConductionNL/private-app', null);

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId());
	}

	public function testNoUserAndOnlyPrivateTokensResolvesNothing(): void {
		$private = $this->makePat(1, 'alice', 'ConductionNL/*');

		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findAll')->willReturn([$private]);

		$this->assertNull((new PatResolver($mapper))->findFor('github', 'ConductionNL/private-app', null));
	}
}
