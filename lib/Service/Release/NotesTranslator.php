<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Release;

use OCA\Versioniq\AppInfo\Application;
use OCA\Versioniq\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Translates a version's release notes into the admin's language on request
 * and keeps the result (releases-notes-reach D3).
 *
 * The notes are read through the normal version listing, so they are the
 * notes the page shows. The translation runs through Nextcloud's task
 * processing (the `core:text2text:translate` task type), backed by whatever
 * translation provider the instance has; the older ITranslationManager is
 * deprecated since Nextcloud 30, below this app's minimum. A result is kept
 * in app config, keyed by source, version and language, so a second request
 * costs the provider nothing.
 *
 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
 */
class NotesTranslator {
	/** App-config key prefix of a kept translation. */
	public const STORE_PREFIX = 'notes_translation.';

	/** Cap on a kept translation, twice the 8 KB cap on the source notes. */
	public const MAX_BYTES = 16384;

	/** The origin language a provider detects itself. */
	private const DETECT_LANGUAGE = 'detect_language';

	public function __construct(
		private InstallerService $installerService,
		private IManager $taskProcessing,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the instance has a provider that translates text.
	 *
	 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
	 */
	public function isAvailable(): bool {
		try {
			return in_array(TextToTextTranslate::ID, $this->taskProcessing->getAvailableTaskTypeIds(), true);
		} catch (Throwable) {
			return false;
		}
	}

	/**
	 * The notes of one version in the target language: the kept translation,
	 * or a new one from the provider, which is then kept.
	 *
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 *
	 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
	 */
	public function translate(NotesTranslationRequest $request): array {
		if (!self::namesVersionAndLanguage($request)) {
			return self::answer(Http::STATUS_BAD_REQUEST, ['message' => 'Name a version and a language such as nl or pt_BR.']);
		}

		$listing = $this->installerService->getAppVersions($request->appId, $request->source);
		$refusal = self::listingRefusal($listing);
		if ($refusal !== null) {
			return $refusal;
		}

		$entry = self::entryFor($listing['versions'], $request->version);
		if (!is_string($entry['changelog'] ?? null) || trim($entry['changelog']) === '') {
			return self::answer(Http::STATUS_NOT_FOUND, ['message' => 'This version has no release notes.']);
		}

		return $this->translatedNotes($request, ['sourceId' => (string)$listing['sourceId']] + $entry);
	}

	/**
	 * Whether the request names a version and a language code such as nl or pt_BR.
	 */
	private static function namesVersionAndLanguage(NotesTranslationRequest $request): bool {
		return preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})?$/', trim($request->language)) === 1 && trim($request->version) !== '';
	}

	/**
	 * The answer for a listing that failed, or null when it listed versions.
	 *
	 * @param array<string, mixed> $listing
	 * @return array{statusCode: int, payload: array<string, mixed>}|null
	 */
	private static function listingRefusal(array $listing): ?array {
		$listingStatus = $listing['statusCode'];
		if ($listingStatus === Http::STATUS_OK && $listing['hasError'] !== true) {
			return null;
		}

		// A listing that failed with 200 (no versions, an error) is the source's failure.
		$message = $listing['error'] ?? null;

		return self::answer($listingStatus === Http::STATUS_OK ? Http::STATUS_BAD_GATEWAY : $listingStatus, [
			'message' => is_string($message) ? $message : 'The versions of this app could not be listed.',
		]);
	}

	/**
	 * The kept translation of a listed entry's notes, or a new one, which is then kept.
	 *
	 * @param array<array-key, mixed> $entry The listed version, with the `sourceId` it was listed from.
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private function translatedNotes(NotesTranslationRequest $request, array $entry): array {
		$targetLanguage = trim($request->language);
		$sourceLanguage = is_string($entry['changelogLanguage'] ?? null) ? $entry['changelogLanguage'] : null;
		$key = self::storeKey($request, (string)$entry['sourceId']);
		$kept = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($kept !== '') {
			return self::answer(Http::STATUS_OK, self::result($kept, ['source' => $sourceLanguage, 'target' => $targetLanguage]) + ['kept' => true]);
		}

		if (!$this->isAvailable()) {
			return self::answer(Http::STATUS_CONFLICT, [
				'message' => 'No machine translation is available: install a translation provider app on this instance first.',
			]);
		}

		$translation = $this->runTranslation($request, $entry);
		if ($translation === null) {
			return self::answer(Http::STATUS_BAD_GATEWAY, ['message' => 'The translation provider could not translate these notes.']);
		}

		$translation = self::capped($translation);
		$this->appConfig->setValueString(Application::APP_ID, $key, $translation);

		return self::answer(Http::STATUS_OK, self::result($translation, ['source' => $sourceLanguage, 'target' => $targetLanguage]) + ['kept' => false]);
	}

	/**
	 * The app-config key of a kept translation: the source, version and
	 * language hashed, so the key stays short whatever the source id.
	 *
	 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
	 */
	public static function storeKey(NotesTranslationRequest $request, string $sourceId): string {
		return self::STORE_PREFIX . sha1($sourceId . '|' . $request->version . '|' . trim($request->language));
	}

	/**
	 * Run the provider over a listed entry's notes, or null when it fails or answers no text.
	 *
	 * @param array<array-key, mixed> $entry
	 */
	private function runTranslation(NotesTranslationRequest $request, array $entry): ?string {
		$sourceLanguage = is_string($entry['changelogLanguage'] ?? null) ? $entry['changelogLanguage'] : null;
		$task = new Task(TextToTextTranslate::ID, [
			'input' => (string)$entry['changelog'],
			'origin_language' => $sourceLanguage ?? self::DETECT_LANGUAGE,
			'target_language' => trim($request->language),
		], Application::APP_ID, $request->userId);
		try {
			$done = $this->taskProcessing->runTask($task);
		} catch (Throwable $error) {
			$this->logger->warning('Versioniq: translating release notes failed', ['exception' => $error]);
			return null;
		}

		return self::outputOf($done);
	}

	/**
	 * A finished task's text, or null when it did not succeed or answered nothing.
	 */
	private function outputOf(Task $done): ?string {
		$output = $done->getOutput()['output'] ?? null;
		if ($done->getStatus() !== Task::STATUS_SUCCESSFUL || !is_string($output) || trim($output) === '') {
			$this->logger->warning('Versioniq: the translation provider answered no text', ['status' => $done->getStatus()]);
			return null;
		}

		return $output;
	}

	/**
	 * The listed entry of a version, or null.
	 *
	 * @param mixed $versions
	 * @return array<array-key, mixed>|null
	 */
	private static function entryFor(mixed $versions, string $version): ?array {
		$matches = is_array($versions)
			? array_filter($versions, static fn (mixed $entry): bool => is_array($entry) && ($entry['version'] ?? null) === $version)
			: [];

		return $matches === [] ? null : reset($matches);
	}

	private static function capped(string $translation): string {
		return strlen($translation) <= self::MAX_BYTES ? $translation : mb_strcut($translation, 0, self::MAX_BYTES, 'UTF-8');
	}

	/**
	 * @param array{source: ?string, target: string} $languages The notes' language and the one translated into.
	 * @return array{translation: string, sourceLanguage: ?string, targetLanguage: string, machineTranslation: true}
	 */
	private static function result(string $translation, array $languages): array {
		return [
			'translation' => $translation,
			'sourceLanguage' => $languages['source'],
			'targetLanguage' => $languages['target'],
			'machineTranslation' => true,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 */
	private static function answer(int $statusCode, array $payload): array {
		return ['statusCode' => $statusCode, 'payload' => $payload];
	}
}
