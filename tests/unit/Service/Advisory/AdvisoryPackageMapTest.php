<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Advisory;

use OCA\Versioniq\Service\Advisory\AdvisoryPackageMap;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryPackageMapTest extends TestCase {
	/**
	 * @param array<string, string|array<string,string>> $apps app id => display name
	 */
	private function map(array $apps): AdvisoryPackageMap {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(array_keys($apps));
		$appManager->method('getAppInfo')->willReturnCallback(
			static fn (string $appId) => isset($apps[$appId]) ? ['name' => $apps[$appId]] : null,
		);

		return new AdvisoryPackageMap($appManager, $this->createMock(LoggerInterface::class));
	}

	/**
	 * The cases that make this class necessary. Every one is taken from the
	 * live feed; none of them resolve by comparing the package name to the app
	 * id directly.
	 *
	 * @dataProvider realWorldNames
	 */
	public function testResolvesTheNamesTheFeedActuallyPublishes(string $package, string $expected): void {
		$map = $this->map([
			'spreed' => 'Talk',
			'groupfolders' => 'Team Folders',
			'user_oidc' => 'OpenID Connect user backend',
			'twofactor_webauthn' => 'WebAuthn',
			'end_to_end_encryption' => 'End-to-End Encryption',
			'photos' => 'Photos',
			'workflowengine' => 'Flow',
			'tables' => 'Tables',
		]);

		self::assertSame($expected, $map->resolve($package));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function realWorldNames(): array {
		return [
			// The id bears no resemblance to the published name.
			'Talk -> spreed' => ['Talk', 'spreed'],
			// The app was renamed; only the current display name matches.
			'Team Folders -> groupfolders' => ['Team Folders', 'groupfolders'],
			// Both spellings appear in the SAME feed for the same app.
			'User OIDC -> user_oidc' => ['User OIDC', 'user_oidc'],
			'user_oidc -> user_oidc' => ['user_oidc', 'user_oidc'],
			// Punctuation and case differences only.
			'Twofactor WebAuthn -> id' => ['Twofactor WebAuthn', 'twofactor_webauthn'],
			'End-to-End Encryption -> id' => ['End-to-End Encryption', 'end_to_end_encryption'],
			// Bundled apps: absent from the App Store catalogue entirely, which
			// is why the installed-app list has to be part of the index.
			'Photos -> photos' => ['Photos', 'photos'],
			'Flow -> workflowengine' => ['Flow', 'workflowengine'],
		];
	}

	public function testServerPackagesResolveToTheServerSentinel(): void {
		$map = $this->map(['files' => 'Files']);

		self::assertSame(AdvisoryPackageMap::SERVER, $map->resolve('Server'));
		self::assertSame(AdvisoryPackageMap::SERVER, $map->resolve('Enterprise Server'));
	}

	/**
	 * Desktop and mobile clients share the feed. An administrator cannot
	 * resolve those from this instance, so surfacing them would be noise.
	 */
	public function testClientPackagesAreDropped(): void {
		$map = $this->map(['files' => 'Files']);

		self::assertNull($map->resolve('Desktop'));
		self::assertNull($map->resolve('Desktop client'));
		self::assertNull($map->resolve('Android Files'));
		self::assertNull($map->resolve('Files iOS'));
	}

	/**
	 * A client name must stay filtered even if some installed app happens to
	 * normalise to it — the filter is checked before the index for exactly
	 * this reason.
	 */
	public function testAClientNameIsFilteredEvenWhenAnAppWouldMatchIt(): void {
		$map = $this->map(['some_app' => 'Desktop']);

		self::assertNull($map->resolve('Desktop'));
	}

	/**
	 * An unknown package is dropped, never guessed. A wrong match attaches a
	 * real advisory to the wrong app and leaves the affected one looking clean.
	 */
	public function testUnknownPackagesResolveToNull(): void {
		$map = $this->map(['files' => 'Files']);

		self::assertNull($map->resolve('Some App Nobody Installed'));
		self::assertNull($map->resolve(''));
		self::assertNull($map->resolve('   '));
	}

	public function testAnAppThatIsNotInstalledDoesNotResolve(): void {
		// Talk's advisories must not attach to anything on an instance without it.
		$map = $this->map(['files' => 'Files']);

		self::assertNull($map->resolve('Talk'));
	}

	/**
	 * An id match must win over a name match, because ids are unique and
	 * display names are not.
	 */
	public function testAnIdMatchIsNotOverwrittenByAnotherAppsName(): void {
		$map = $this->map([
			'tables' => 'Tables',
			'other_app' => 'tables',
		]);

		self::assertSame('tables', $map->resolve('tables'));
	}

	/**
	 * info.xml `name` is sometimes a per-language map rather than a string.
	 */
	public function testHandlesATranslatedNameMap(): void {
		$map = $this->map(['deck' => ['en' => 'Deck', 'de' => 'Deck-Board']]);

		self::assertSame('deck', $map->resolve('Deck'));
		self::assertSame('deck', $map->resolve('Deck-Board'));
	}
}
