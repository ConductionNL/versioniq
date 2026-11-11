<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

use OCP\Files\SimpleFS\ISimpleFile;

/**
 * Minimal in-memory stand-in for `ISimpleFile`, used instead of a PHPUnit mock
 * because `ArtifactCache` drives stateful put/get/delete sequences across
 * several calls — a fake is far more readable than chained mock expectations.
 */
final class FakeSimpleFile implements ISimpleFile {
	public function __construct(
		private FakeSimpleFolder $parent,
		private string $name,
		private string $content,
	) {
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSize(): int|float {
		return strlen($this->content);
	}

	public function getETag(): string {
		return 'etag';
	}

	public function getMTime(): int {
		return 0;
	}

	public function getContent(): string {
		return $this->content;
	}

	public function putContent($data): void {
		$this->content = (string)$data;
		$this->parent->rememberFile($this);
	}

	public function delete(): void {
		$this->parent->forgetFile($this->name);
	}

	public function getMimeType(): string {
		return 'application/octet-stream';
	}

	public function getExtension(): string {
		return '';
	}

	public function read() {
		return false;
	}

	public function write() {
		return false;
	}
}
