<?php

declare(strict_types=1);

namespace OCA\Versioniq\Tests\Unit\Service\Cache;

use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Minimal in-memory stand-in for `ISimpleFolder`; see {@see FakeSimpleFile}.
 */
final class FakeSimpleFolder implements ISimpleFolder {
	/** @var array<string, FakeSimpleFile> */
	private array $files = [];

	/** @var array<string, FakeSimpleFolder> */
	private array $folders = [];

	public function __construct(
		private string $name,
		/** Invoked on delete() so the owning parent drops this folder from its own listing — mirrors the real IAppData/ISimpleFolder behaviour. */
		private ?\Closure $onDelete = null,
	) {
	}

	public function getDirectoryListing(): array {
		return array_values($this->files);
	}

	public function fileExists(string $name): bool {
		return isset($this->files[$name]);
	}

	public function getFile(string $name): ISimpleFile {
		if (!isset($this->files[$name])) {
			throw new NotFoundException($name);
		}

		return $this->files[$name];
	}

	public function newFile(string $name, $content = null): ISimpleFile {
		$file = new FakeSimpleFile($this, $name, is_string($content) ? $content : '');
		$this->files[$name] = $file;

		return $file;
	}

	public function delete(): void {
		$this->files = [];
		$this->folders = [];
		if ($this->onDelete !== null) {
			($this->onDelete)();
		}
	}

	public function getName(): string {
		return $this->name;
	}

	public function getFolder(string $name): ISimpleFolder {
		if (!isset($this->folders[$name])) {
			throw new NotFoundException($name);
		}

		return $this->folders[$name];
	}

	public function newFolder(string $path): ISimpleFolder {
		$folder = new FakeSimpleFolder($path, function () use ($path): void {
			unset($this->folders[$path]);
		});
		$this->folders[$path] = $folder;

		return $folder;
	}

	/**
	 * Added to ISimpleFolder in Nextcloud 35; harmless extra method on 32-34.
	 */
	public function getOrCreateFolder(string $path, int $maxRetries = 5): ISimpleFolder {
		return $this->folders[$path] ?? $this->newFolder($path);
	}

	public function rememberFile(FakeSimpleFile $file): void {
		$this->files[$file->getName()] = $file;
	}

	public function forgetFile(string $name): void {
		unset($this->files[$name]);
	}
}
