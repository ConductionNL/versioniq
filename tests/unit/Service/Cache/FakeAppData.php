<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Minimal in-memory stand-in for `IAppData`; see {@see FakeSimpleFile}.
 */
final class FakeAppData implements IAppData {
	/** @var array<string, FakeSimpleFolder> */
	private array $folders = [];

	public function getFolder(string $name): ISimpleFolder {
		if (!isset($this->folders[$name])) {
			throw new NotFoundException($name);
		}

		return $this->folders[$name];
	}

	public function getDirectoryListing(): array {
		return array_values($this->folders);
	}

	public function newFolder(string $name): ISimpleFolder {
		$folder = new FakeSimpleFolder($name, function () use ($name): void {
			unset($this->folders[$name]);
		});
		$this->folders[$name] = $folder;

		return $folder;
	}
}
