<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Release;

use OCA\Versioniq\Service\InstallerService;
use OCA\Versioniq\Service\Release\NotesTranslator;
use OCP\IAppConfig;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Release notes are translated on request through the instance's translation
 * provider, once: the result is kept and a second request is served from it
 * (releases-notes-reach 2.2).
 *
 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
 */
#[CoversClass(NotesTranslator::class)]
final class NotesTranslatorTest extends TestCase {
	/** @var array<string, string> */
	private array $store = [];

	/** @var list<Task> */
	private array $tasks = [];

	private InstallerService&MockObject $installer;
	private IManager&MockObject $taskProcessing;

	protected function setUp(): void {
		parent::setUp();
		$this->installer = $this->createMock(InstallerService::class);
		$this->taskProcessing = $this->createMock(IManager::class);
		$this->listing([
			['version' => '2.0.0', 'changelog' => 'Fixes the sync', 'changelogLanguage' => 'en'],
			['version' => '1.9.0', 'changelog' => 'Body from a forge', 'changelogLanguage' => null],
			['version' => '1.8.0', 'changelog' => null, 'changelogLanguage' => null],
		]);
	}

	/**
	 * @param list<array<string, mixed>> $versions
	 */
	private function listing(array $versions, int $statusCode = 200, bool $hasError = false, ?string $error = null): void {
		$listing = [
			'installedVersion' => '1.9.0', 'availableVersions' => $versions, 'versions' => $versions,
			'source' => 'appstore', 'sourceId' => 'appstore', 'statusCode' => $statusCode, 'hasError' => $hasError,
		];
		if ($error !== null) {
			$listing['error'] = $error;
		}
		$this->installer->method('getAppVersions')->willReturn($listing);
	}

	private function provider(bool $available, ?string $answer = 'Verhelpt de synchronisatie', int $status = Task::STATUS_SUCCESSFUL): void {
		$this->taskProcessing->method('getAvailableTaskTypeIds')->willReturn($available ? ['core:text2text', TextToTextTranslate::ID] : ['core:text2text']);
		$this->taskProcessing->method('runTask')->willReturnCallback(
			function (Task $task) use ($answer, $status): Task {
				$this->tasks[] = $task;
				$task->setStatus($status);
				$task->setOutput($answer === null ? null : ['output' => $answer]);

				return $task;
			},
		);
	}

	private function translator(): NotesTranslator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->store[$app . '/' . $key] ?? $default,
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$app . '/' . $key] = $value;

				return true;
			},
		);

		return new NotesTranslator($this->installer, $this->taskProcessing, $appConfig, $this->createMock(LoggerInterface::class));
	}

	public function testTranslatesOnceAndServesTheSecondRequestFromTheStore(): void {
		$this->provider(true);
		$translator = $this->translator();

		$first = $translator->translate('deck', '2.0.0', 'nl', null, 'admin');
		$second = $translator->translate('deck', '2.0.0', 'nl', null, 'admin');

		self::assertSame(200, $first['statusCode']);
		self::assertSame([
			'translation' => 'Verhelpt de synchronisatie', 'sourceLanguage' => 'en', 'targetLanguage' => 'nl',
			'machineTranslation' => true, 'kept' => false,
		], $first['payload']);
		self::assertSame(200, $second['statusCode']);
		self::assertSame('Verhelpt de synchronisatie', $second['payload']['translation']);
		self::assertTrue($second['payload']['kept']);
		self::assertCount(1, $this->tasks);
		self::assertSame(
			['versioniq/' . NotesTranslator::storeKey('appstore', '2.0.0', 'nl') => 'Verhelpt de synchronisatie'],
			$this->store,
		);
	}

	public function testTheTaskCarriesTheNotesAndBothLanguages(): void {
		$this->provider(true);

		$this->translator()->translate('deck', '2.0.0', 'pt_BR', null, 'admin');

		$task = $this->tasks[0];
		self::assertSame(TextToTextTranslate::ID, $task->getTaskTypeId());
		self::assertSame(['input' => 'Fixes the sync', 'origin_language' => 'en', 'target_language' => 'pt_BR'], $task->getInput());
		self::assertSame('versioniq', $task->getAppId());
		self::assertSame('admin', $task->getUserId());
	}

	public function testNotesWithoutALanguageLetTheProviderDetectIt(): void {
		$this->provider(true);

		$result = $this->translator()->translate('deck', '1.9.0', 'nl', null, 'admin');

		self::assertSame(200, $result['statusCode']);
		self::assertNull($result['payload']['sourceLanguage']);
		self::assertSame('detect_language', $this->tasks[0]->getInput()['origin_language']);
	}

	public function testEachLanguageAndVersionIsKeptApart(): void {
		$this->provider(true);
		$translator = $this->translator();

		$translator->translate('deck', '2.0.0', 'nl', null, 'admin');
		$translator->translate('deck', '2.0.0', 'de', null, 'admin');
		$translator->translate('deck', '1.9.0', 'nl', null, 'admin');

		self::assertCount(3, $this->tasks);
		self::assertCount(3, $this->store);
	}

	public function testWithoutAProviderTheAnswerIs409AndNothingRuns(): void {
		$this->provider(false);

		$result = $this->translator()->translate('deck', '2.0.0', 'nl', null, 'admin');

		self::assertSame(409, $result['statusCode']);
		self::assertSame('No machine translation is available: install a translation provider app on this instance first.', $result['payload']['message']);
		self::assertSame([], $this->tasks);
		self::assertSame([], $this->store);
	}

	public function testAKeptTranslationIsServedEvenWithoutAProvider(): void {
		$this->provider(false);
		$this->store['versioniq/' . NotesTranslator::storeKey('appstore', '2.0.0', 'nl')] = 'Eerder vertaald';

		$result = $this->translator()->translate('deck', '2.0.0', 'nl', null, 'admin');

		self::assertSame(200, $result['statusCode']);
		self::assertSame('Eerder vertaald', $result['payload']['translation']);
	}

	public function testAVersionWithoutNotesIs404(): void {
		$this->provider(true);
		$translator = $this->translator();

		self::assertSame(404, $translator->translate('deck', '1.8.0', 'nl', null, 'admin')['statusCode']);
		self::assertSame(404, $translator->translate('deck', '9.9.9', 'nl', null, 'admin')['statusCode']);
		self::assertSame([], $this->tasks);
	}

	public function testAnInvalidLanguageOrMissingVersionIs400(): void {
		$this->provider(true);
		$translator = $this->translator();

		foreach (['', 'dutch', 'nl/../x', 'NL'] as $language) {
			self::assertSame(400, $translator->translate('deck', '2.0.0', $language, null, 'admin')['statusCode'], $language);
		}
		self::assertSame(400, $translator->translate('deck', ' ', 'nl', null, 'admin')['statusCode']);
		self::assertSame([], $this->tasks);
	}

	public function testAFailedProviderIs502AndKeepsNothing(): void {
		$this->taskProcessing->method('getAvailableTaskTypeIds')->willReturn([TextToTextTranslate::ID]);
		$this->taskProcessing->method('runTask')->willThrowException(new RuntimeException('provider down'));

		$result = $this->translator()->translate('deck', '2.0.0', 'nl', null, 'admin');

		self::assertSame(502, $result['statusCode']);
		self::assertSame([], $this->store);
	}

	public function testAnUnsuccessfulOrEmptyTaskIs502(): void {
		$this->provider(true, 'ignored', Task::STATUS_FAILED);
		self::assertSame(502, $this->translator()->translate('deck', '2.0.0', 'nl', null, 'admin')['statusCode']);
	}

	public function testAListingErrorIsPassedOn(): void {
		$this->installer = $this->createMock(InstallerService::class);
		$this->listing([], 403, true, 'This core app cannot be managed from Versioniq.');
		$this->provider(true);

		$result = $this->translator()->translate('files', '2.0.0', 'nl', null, 'admin');

		self::assertSame(403, $result['statusCode']);
		self::assertSame('This core app cannot be managed from Versioniq.', $result['payload']['message']);
	}

	public function testAListingThatFailedWith200IsABadGateway(): void {
		$this->installer = $this->createMock(InstallerService::class);
		$this->listing([], 200, true, 'App is not available in the Nextcloud App Store.');
		$this->provider(true);

		self::assertSame(502, $this->translator()->translate('deck', '2.0.0', 'nl', null, 'admin')['statusCode']);
	}

	public function testALongTranslationIsCappedAtSixteenKilobytesOnACharacterBoundary(): void {
		$this->provider(true, str_repeat('é', 10000));

		$result = $this->translator()->translate('deck', '2.0.0', 'nl', null, 'admin');

		$kept = $result['payload']['translation'];
		self::assertLessThanOrEqual(NotesTranslator::MAX_BYTES, strlen($kept));
		self::assertTrue(mb_check_encoding($kept, 'UTF-8'));
		self::assertSame(8192, mb_strlen($kept));
	}

	public function testAvailabilityIsFalseWhenTaskProcessingFails(): void {
		$this->taskProcessing->method('getAvailableTaskTypeIds')->willThrowException(new RuntimeException('db down'));

		self::assertFalse($this->translator()->isAvailable());
	}
}
