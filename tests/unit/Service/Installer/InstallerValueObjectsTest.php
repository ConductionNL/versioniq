<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Installer;

use Error;
use OCA\Versioniq\Service\Installer\ArchiveCheck;
use OCA\Versioniq\Service\Installer\ExternalInstall;
use OCA\Versioniq\Service\Installer\ExternalInstallOptions;
use OCA\Versioniq\Service\Installer\ExternalInstallRequest;
use OCA\Versioniq\Service\Installer\ExternalInstallRun;
use OCA\Versioniq\Service\Installer\InstalledFolder;
use OCA\Versioniq\Service\Installer\ReleaseTarget;
use OCA\Versioniq\Service\Installer\SignedRelease;
use OCA\Versioniq\Service\Installer\SignedReleaseRun;
use OCA\Versioniq\Service\Installer\StagedRelease;
use OCA\Versioniq\Service\Installer\TempArchive;
use OCA\Versioniq\Service\Source\SourceBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The installer's carrier objects: what each one hands on between the install
 * steps, which of their fields the pipeline is allowed to swap mid-install
 * (the release of a signed run on a cache fallback, the app info read back
 * from the new files) and which are frozen once constructed.
 */
#[CoversClass(TempArchive::class)]
#[CoversClass(ReleaseTarget::class)]
#[CoversClass(InstalledFolder::class)]
#[CoversClass(SignedReleaseRun::class)]
#[CoversClass(StagedRelease::class)]
#[CoversClass(ExternalInstall::class)]
#[UsesClass(SignedRelease::class)]
#[UsesClass(ArchiveCheck::class)]
#[UsesClass(ExternalInstallRun::class)]
#[UsesClass(ExternalInstallRequest::class)]
#[UsesClass(ExternalInstallOptions::class)]
#[UsesClass(SourceBinding::class)]
final class InstallerValueObjectsTest extends TestCase {
	public function testATempArchiveCarriesTheDownloadFileAndTheExtractionFolder(): void {
		$temp = new TempArchive('/tmp/vq/app.tar.gz', '/tmp/vq/extract');

		self::assertSame('/tmp/vq/app.tar.gz', $temp->file);
		self::assertSame('/tmp/vq/extract', $temp->folder);
	}

	public function testATempArchiveIsFrozenOnceConstructed(): void {
		$temp = new TempArchive('/tmp/vq/app.tar.gz', '/tmp/vq/extract');

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');

		/** @psalm-suppress InaccessibleProperty Asserting the readonly contract. */
		$temp->file = '/tmp/other.tar.gz';
	}

	public function testAReleaseTargetOfAFreshInstallHasNoPreviousPath(): void {
		$target = new ReleaseTarget('/tmp/vq/extract/someapp', null, '/apps/someapp');

		self::assertSame('/tmp/vq/extract/someapp', $target->root);
		self::assertNull($target->previousPath);
		self::assertSame('/apps/someapp', $target->destination);
	}

	public function testAReleaseTargetOfAnUpdateNamesTheInstalledFolder(): void {
		$target = new ReleaseTarget('/tmp/vq/extract/someapp', '/apps/someapp', '/apps/someapp');

		self::assertSame('/apps/someapp', $target->previousPath);
	}

	public function testAnInstalledFolderStartsWithoutAppInfoAndTakesItAfterTheSwap(): void {
		$installed = new InstalledFolder('/apps/someapp', '/apps/someapp.appversion-backup');

		self::assertSame('/apps/someapp', $installed->appPath);
		self::assertSame('/apps/someapp.appversion-backup', $installed->backup);
		self::assertSame([], $installed->info);

		// The info is read back from the new files, so it is not readonly.
		$installed->info = ['id' => 'someapp', 'version' => '2.0.0'];

		self::assertSame(['id' => 'someapp', 'version' => '2.0.0'], $installed->info);
	}

	public function testAnInstalledFolderOfAFreshInstallHasNoBackup(): void {
		self::assertNull((new InstalledFolder('/apps/someapp', null))->backup);
	}

	public function testASignedReleaseRunKeepsTheAppAndTempPathsButSwapsTheRelease(): void {
		$temp = new TempArchive('/tmp/vq/app.tar.gz', '/tmp/vq/extract');
		$release = new SignedRelease('https://store/app-2.0.0.tar.gz', 'sig', 'cert', '2.0.0');
		$run = new SignedReleaseRun('someapp', $release, $temp);

		self::assertSame('someapp', $run->appId);
		self::assertSame($release, $run->release);
		self::assertSame($temp, $run->temp);

		// A cache fallback replaces the release with the cached materials.
		$cached = new SignedRelease('/cache/app-2.0.0.tar.gz', 'cached-sig', 'cached-cert', '2.0.0');
		$run->release = $cached;

		self::assertSame($cached, $run->release);
		self::assertSame('/cache/app-2.0.0.tar.gz', $run->release->download);
		self::assertSame('someapp', $run->appId);
	}

	public function testASignedReleaseRunsAppIdIsFrozen(): void {
		$run = new SignedReleaseRun('someapp', new SignedRelease('u', 's', 'c', '2.0.0'), new TempArchive('/f', '/d'));

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');

		/** @psalm-suppress InaccessibleProperty Asserting the readonly contract. */
		$run->appId = 'otherapp';
	}

	public function testAStagedReleaseCarriesTheExtractedRootInfoAndDestination(): void {
		$staged = new StagedRelease('/tmp/vq/extract/someapp', ['id' => 'someapp', 'version' => '2.0.0'], null, '/apps/someapp');

		self::assertSame('/tmp/vq/extract/someapp', $staged->root);
		self::assertSame(['id' => 'someapp', 'version' => '2.0.0'], $staged->info);
		self::assertNull($staged->previousPath);
		self::assertSame('/apps/someapp', $staged->destination);
	}

	public function testAnExternalInstallCarriesItsRunIntegrityOutcomeAndOrphanedMigrations(): void {
		$run = $this->externalRun();
		$check = new ArchiveCheck(str_repeat('a', 64), true, null);
		$staged = new StagedRelease('/tmp/vq/extract/someapp', ['id' => 'someapp'], '/apps/someapp', '/apps/someapp');

		$install = new ExternalInstall($run, $check, $staged, ['Version1000Date20260101000000']);

		self::assertSame($run, $install->run);
		self::assertSame($check, $install->check);
		self::assertSame($staged, $install->staged);
		self::assertSame(['Version1000Date20260101000000'], $install->orphanedMigrations);
	}

	public function testAnExternalInstallWithoutAMigrationDiffCarriesNull(): void {
		$install = new ExternalInstall(
			$this->externalRun(),
			new ArchiveCheck(str_repeat('b', 64), false, 'Checksum not recorded for this release.'),
			new StagedRelease('/tmp/vq/extract/someapp', [], null, '/apps/someapp'),
			null,
		);

		self::assertNull($install->orphanedMigrations);
		self::assertFalse($install->check->recordedShaMatched);
		self::assertSame('Checksum not recorded for this release.', $install->check->integrityWarning);
	}

	private function externalRun(): ExternalInstallRun {
		$request = new ExternalInstallRequest('someapp', '2.0.0', [], new ExternalInstallOptions(false, false));

		return new ExternalInstallRun($request, new SourceBinding(SourceBinding::KIND_APPSTORE, []), '1.0.0');
	}
}
