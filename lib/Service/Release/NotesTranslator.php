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
	 * @param string $appId The app
	 * @param string $version The version whose notes to translate
	 * @param string $targetLanguage A language code such as `nl` or `pt_BR`
	 * @param string|null $source A source override, as the version listing takes it
	 * @param string|null $userId The admin asking, recorded on the task
	 *
	 * @return array{statusCode: int, payload: array<string, mixed>}
	 *
	 * @spec openspec/changes/releases-notes-reach/specs/changelog-visibility/spec.md
	 */
	public function translate(string $appId, string $version, string $targetLanguage, ?string $source, ?string $userId): array {
		$targetLanguage = trim($targetLanguage);
		if (preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})?$/', $targetLanguage) !== 1 || trim($version) === '') {
			return self::answer(Http::STATUS_BAD_REQUEST, ['message' => 'Name a version and a language such as nl or pt_BR.']);
		}

		$listing = $this->installerService->getAppVersions($appId, $source);
		$listingStatus = $listing['statusCode'];
		if ($listingStatus !== Http::STATUS_OK || $listing['hasError'] === true) {
			// A listing that failed with 200 (no versions, an error) is the source's failure.
			return self::answer($listingStatus === Http::STATUS_OK ? Http::STATUS_BAD_GATEWAY : $listingStatus, [
				'message' => is_string($listing['error'] ?? null) ? $listing['error'] : 'The versions of this app could not be listed.',
			]);
		}

		$entry = self::entryFor($listing['versions'], $version);
		$notes = $entry['changelog'] ?? null;
		if (!is_string($notes) || trim($notes) === '') {
			return self::answer(Http::STATUS_NOT_FOUND, ['message' => 'This version has no release notes.']);
		}
		$sourceLanguage = is_string($entry['changelogLanguage'] ?? null) ? $entry['changelogLanguage'] : null;

		$key = self::storeKey((string)$listing['sourceId'], $version, $targetLanguage);
		$kept = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($kept !== '') {
			return self::answer(Http::STATUS_OK, self::result($kept, $sourceLanguage, $targetLanguage, true));
		}

		if (!$this->isAvailable()) {
			return self::answer(Http::STATUS_CONFLICT, [
				'message' => 'No machine translation is available: install a translation provider app on this instance first.',
			]);
		}

		$translation = $this->runTranslation($notes, $sourceLanguage, $targetLanguage, $userId);
		if ($translation === null) {
			return self::answer(Http::STATUS_BAD_GATEWAY, ['message' => 'The translation provider could not translate these notes.']);
		}

		$translation = self::capped($translation);
		$this->appConfig->setValueString(Application::APP_ID, $key, $translation);

		return self::answer(Http::STATUS_OK, self::result($translation, $sourceLanguage, $targetLanguage, false));
	}

	/**
	 * The app-config key of a kept translation: the source, version and
	 * language hashed, so the key stays short whatever the source id.
	 */
	public static function storeKey(string $sourceId, string $version, string $language): string {
		return self::STORE_PREFIX . sha1($sourceId . '|' . $version . '|' . $language);
	}

	/**
	 * Run the provider; null when it fails or answers no text.
	 */
	private function runTranslation(string $notes, ?string $sourceLanguage, string $targetLanguage, ?string $userId): ?string {
		$task = new Task(TextToTextTranslate::ID, [
			'input' => $notes,
			'origin_language' => $sourceLanguage ?? self::DETECT_LANGUAGE,
			'target_language' => $targetLanguage,
		], Application::APP_ID, $userId);

		try {
			$done = $this->taskProcessing->runTask($task);
		} catch (Throwable $error) {
			$this->logger->warning('Versioniq: translating release notes failed', ['exception' => $error]);
			return null;
		}

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
		if (!is_array($versions)) {
			return null;
		}
		/** @var mixed $entry */
		foreach ($versions as $entry) {
			if (is_array($entry) && ($entry['version'] ?? null) === $version) {
				return $entry;
			}
		}

		return null;
	}

	private static function capped(string $translation): string {
		return strlen($translation) <= self::MAX_BYTES ? $translation : mb_strcut($translation, 0, self::MAX_BYTES, 'UTF-8');
	}

	/**
	 * @return array{translation: string, sourceLanguage: ?string, targetLanguage: string, machineTranslation: true, kept: bool}
	 */
	private static function result(string $translation, ?string $sourceLanguage, string $targetLanguage, bool $kept): array {
		return [
			'translation' => $translation,
			'sourceLanguage' => $sourceLanguage,
			'targetLanguage' => $targetLanguage,
			'machineTranslation' => true,
			'kept' => $kept,
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
