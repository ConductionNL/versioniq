<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Installer;

/**
 * An app archive that is already on this server, installed instead of a
 * download: an admin's upload (install-manual-upload D2). The installers
 * read it in place of the release URL and record the install under source
 * id `upload`.
 *
 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.1
 */
final class LocalArchive {
	/** The source id an install from an uploaded archive is audited and cached under. */
	public const SOURCE_ID = 'upload';

	/** Whether the App Store's signature verifies the archive. */
	public readonly bool $signed;

	/**
	 * @param string $path The archive file.
	 * @param string $sha256 Its SHA-256, as shown to the admin before the install.
	 * @param array<string, mixed>|null $release The App Store release whose signature verifies it; null when unsigned.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $sha256,
		public readonly ?array $release = null,
	) {
		$this->signed = $release !== null;
	}

	/**
	 * What the audit row says about the archive: signed or unsigned, and its SHA-256.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.2
	 */
	public function auditNote(): string {
		return sprintf(
			'Installed from an uploaded archive, %s, SHA-256 %s.',
			$this->signed ? 'signed by the App Store' : 'unsigned',
			$this->sha256,
		);
	}

	/**
	 * An installer's audit message with the archive note after it.
	 *
	 * @spec openspec/changes/install-manual-upload/tasks.md#task-2.2
	 */
	public function withAuditNote(?string $message): string {
		return $message === null || $message === '' ? $this->auditNote() : $message . ' ' . $this->auditNote();
	}
}
