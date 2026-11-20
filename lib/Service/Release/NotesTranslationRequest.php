<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Release;

/**
 * What an admin asks to have translated: one app version's release notes,
 * into one language, read from the given source or the app's default one
 * (releases-notes-reach D3).
 *
 * @spec openspec/changes/releases-notes-reach/tasks.md
 */
final class NotesTranslationRequest {
	/**
	 * @param string      $appId    The app whose notes are translated.
	 * @param string      $version  The version whose notes are translated.
	 * @param string      $language The language to translate into, such as nl or pt_BR.
	 * @param string|null $source   A source id to read the notes from, or null for the app's default.
	 * @param string|null $userId   The admin who asked; the translation task runs for them.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $version,
		public readonly string $language,
		public readonly ?string $source = null,
		public readonly ?string $userId = null,
	) {
	}
}
