<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use OCA\Versioniq\Service\Source\Forge;
use OCA\Versioniq\Service\Source\ForgeUrls;
use PHPUnit\Framework\TestCase;

/**
 * The endpoints each forge dialect builds (sources-gitlab-forge, design D1).
 *
 * @covers \OCA\Versioniq\Service\Source\Forge
 * @uses \OCA\Versioniq\Service\Source\ForgeUrls
 *
 * @spec openspec/changes/sources-gitlab-forge/tasks.md#task-1.1
 */
final class ForgeDialectTest extends TestCase {

	/**
	 * GitLab: the project path url-encoded as one segment, a token info endpoint, no advisories.
	 */
	public function testGitlabBuildsProjectEndpointsAndHasNoAdvisories(): void {
		$forge = new Forge('gitlab', new ForgeUrls('https://gitlab.com/api/v4', 'https://gitlab.com', 'https://gitlab.com/-/user_settings/personal_access_tokens'), Forge::SCHEME_BEARER, false, Forge::DIALECT_GITLAB);

		self::assertSame('https://gitlab.com/api/v4/projects/group%2Fsub%2Fproject/releases?per_page=100', $forge->releasesEndpoint('group/sub/project'));
		self::assertSame('https://gitlab.com/api/v4/personal_access_tokens/self', $forge->tokenInfoEndpoint());
		self::assertSame('https://gitlab.com/api/v4/user', $forge->userEndpoint());
		self::assertNull($forge->advisoriesEndpoint('group/sub/project'));
	}

	/**
	 * GitHub and Forgejo keep their paths; the dialect defaults to GitHub's.
	 */
	public function testTheGithubDialectKeepsItsPaths(): void {
		$forge = new Forge('github', new ForgeUrls('https://api.github.com', 'https://github.com', 'https://github.com/settings/tokens'), Forge::SCHEME_BEARER, true);

		self::assertSame(Forge::DIALECT_GITHUB, $forge->dialect);
		self::assertSame('https://api.github.com/repos/acme/app/releases?per_page=100', $forge->releasesEndpoint('acme/app'));
		self::assertSame('https://api.github.com/repos/acme/app/security-advisories?per_page=100', $forge->advisoriesEndpoint('acme/app'));
		self::assertNull($forge->tokenInfoEndpoint());
	}
}
