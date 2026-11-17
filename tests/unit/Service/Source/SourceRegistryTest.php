<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\Versioniq\Service\Source\AppStoreSource;
use OCA\Versioniq\Service\Source\ForgeReleaseSource;
use OCA\Versioniq\Service\Source\SourceBinding;
use OCA\Versioniq\Service\Source\SourceRegistry;
use PHPUnit\Framework\TestCase;

final class SourceRegistryTest extends TestCase {
	public function testParseAppstore(): void {
		$binding = SourceRegistry::parseSourceId('appstore');

		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
	}

	public function testParseEmptyDefaultsToAppstore(): void {
		$binding = SourceRegistry::parseSourceId('');

		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
	}

	public function testParseGithubProducesBinding(): void {
		$binding = SourceRegistry::parseSourceId('github:ConductionNL/openregister');

		$this->assertSame(SourceBinding::KIND_GITHUB_RELEASE, $binding->kind);
		$this->assertSame('ConductionNL/openregister', $binding->getOwnerRepo());
	}

	public function testParseGithubMissingRepoRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('github:ConductionNL');
	}

	public function testParseGithubEmptyOwnerOrRepoRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('github:/openregister');
	}

	public function testParseUnknownPrefixRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('bitbucket:ConductionNL/openregister');
	}

	public function testParseCodeberg(): void {
		$binding = SourceRegistry::parseSourceId('codeberg:Conduction/pipelinq');

		$this->assertSame(SourceBinding::KIND_GITHUB_RELEASE, $binding->kind);
		$this->assertSame('codeberg', $binding->getForge());
		$this->assertSame('codeberg:Conduction/pipelinq', $binding->getId());
	}

	public function testParseCodebergMissingRepoRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('codeberg:Conduction');
	}

	public function testParseCodebergEmptyOwnerRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('codeberg:/pipelinq');
	}

	public function testParseCodebergEmptyRepoRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('codeberg:Conduction/');
	}

	public function testListAvailableIncludesAppstoreAndGithub(): void {
		$registry = new SourceRegistry(
			$this->createMock(AppStoreSource::class),
			$this->createMock(ForgeReleaseSource::class),
		);

		$ids = array_map(static fn (array $s): string => $s['id'], $registry->listAvailable());

		$this->assertContains('appstore', $ids);
		$this->assertContains('github', $ids);
	}

	public function testParseForgejo(): void {
		$binding = SourceRegistry::parseSourceId('forgejo:acme/widget');

		$this->assertSame('forgejo', $binding->getForge());
		$this->assertSame('forgejo:acme/widget', $binding->getId());
	}

	public function testListAvailableOffersForgejoNotCodeberg(): void {
		$registry = new SourceRegistry(
			$this->createMock(AppStoreSource::class),
			$this->createMock(ForgeReleaseSource::class),
		);
		$ids = array_column($registry->listAvailable(), 'id');

		$this->assertContains('forgejo', $ids);
		$this->assertNotContains('codeberg', $ids);
	}

	/**
	 * sources-gitlab-forge 1.2: a gitlab source id keeps the whole path.
	 */
	public function testParseGitlabKeepsTheNestedPath(): void {
		$binding = SourceRegistry::parseSourceId('gitlab:group/sub/project');

		$this->assertSame('gitlab', $binding->getForge());
		$this->assertSame('gitlab:group/sub/project', $binding->getId());
		$this->assertSame('group/sub/project', $binding->getOwnerRepo());
	}

	public function testParseGitlabRefusesATraversalSegment(): void {
		$this->expectException(\InvalidArgumentException::class);

		SourceRegistry::parseSourceId('gitlab:group/../x');
	}
}
