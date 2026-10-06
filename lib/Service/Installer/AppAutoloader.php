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

use OCP\App\IAppManager;

/**
 * Registers a just-extracted app's PSR-4 autoloading before its migrations run,
 * on every supported Nextcloud major.
 *
 * `\OC_App::registerAutoloading()` is what this used to call directly. It is
 * private API and Nextcloud 35 REMOVED it: the logic moved to the equally
 * private `OC\App\AppManager::registerAutoloading()`, which is what core's own
 * `OC\Installer` calls on 35. Calling the static unguarded made every finalize
 * on 35 die with "Call to undefined method OC_App::registerAutoloading()" after
 * the new files had already been swapped in.
 *
 * Resolution order, first available wins:
 *  1. `\OC_App::registerAutoloading()` (Nextcloud 32-34, unchanged behaviour);
 *  2. the concrete app manager's `registerAutoloading()` (Nextcloud 35+), with
 *     `$force` set exactly as core's installer does on 35;
 *  3. a plain-PHP equivalent of what both of the above do: the app's own
 *     `composer/autoload.php` when shipped, otherwise a PSR-4 loader for the
 *     app namespace over `lib/`.
 *
 * @psalm-api
 */
final class AppAutoloader {
	/**
	 * Keys (`appId-path`) already registered by the plain-PHP fallback.
	 *
	 * @var array<string, true>
	 */
	private static array $registered = [];

	/**
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml`; only `namespace` is read, as a fallback.
	 */
	public static function register(string $appId, string $appPath, IAppManager $appManager, array $info = []): void {
		if (self::legacyRegistrarAvailable()) {
			/** @psalm-suppress UndefinedMethod Private API, guarded: present on Nextcloud 32-34 only. */
			\OC_App::registerAutoloading($appId, $appPath);
			return;
		}

		if (method_exists($appManager, 'registerAutoloading')) {
			// Concrete OC\App\AppManager on Nextcloud 35+; not on the interface.
			/** @psalm-suppress UndefinedInterfaceMethod */
			$appManager->registerAutoloading($appId, $appPath, true);
			return;
		}

		self::registerFallback(self::resolveNamespace($appId, $appManager, $info), $appId, $appPath);
	}

	/**
	 * Whether the legacy static (Nextcloud 32-34) can be called.
	 */
	public static function legacyRegistrarAvailable(): bool {
		return class_exists(\OC_App::class) && method_exists(\OC_App::class, 'registerAutoloading');
	}

	/**
	 * The app's fully qualified top-level namespace, e.g. `OCA\Versioniq`.
	 * Mirrors `AppManager::getAppNamespace()`: info.xml `<namespace>`, else the
	 * capitalised app id.
	 *
	 * @param array<string, mixed> $info
	 */
	public static function resolveNamespace(string $appId, IAppManager $appManager, array $info = []): string {
		if (method_exists($appManager, 'getAppNamespace')) {
			try {
				// Public since Nextcloud 34.
				$namespace = $appManager->getAppNamespace($appId);
				if ($namespace !== '') {
					return $namespace;
				}
			} catch (\Throwable) {
				// Fall through to info.xml.
			}
		}

		$declared = isset($info['namespace']) && is_string($info['namespace']) ? trim($info['namespace']) : '';
		if ($declared !== '') {
			return 'OCA\\' . $declared;
		}

		return 'OCA\\' . ucfirst($appId);
	}

	/**
	 * Map a class to its file under `<appPath>/lib/`, or null when the class is
	 * outside `$namespace`.
	 */
	public static function classFile(string $namespace, string $appPath, string $class): ?string {
		$prefix = rtrim($namespace, '\\') . '\\';
		if (!str_starts_with($class, $prefix)) {
			return null;
		}

		$relative = substr($class, strlen($prefix));
		if ($relative === '') {
			return null;
		}

		return rtrim($appPath, '/') . '/lib/' . str_replace('\\', '/', $relative) . '.php';
	}

	/**
	 * Plain-PHP equivalent of core's registration, used only when neither
	 * private registrar exists.
	 */
	public static function registerFallback(string $namespace, string $appId, string $appPath): void {
		$appPath = rtrim($appPath, '/');
		$key = $appId . '-' . $appPath;
		if (isset(self::$registered[$key])) {
			return;
		}
		self::$registered[$key] = true;

		if (file_exists($appPath . '/composer/autoload.php')) {
			/** @psalm-suppress UnresolvableInclude Runtime path of the app being installed. */
			require_once $appPath . '/composer/autoload.php';
			return;
		}
		if (!is_dir($appPath . '/lib')) {
			return;
		}

		spl_autoload_register(static function (string $class) use ($namespace, $appPath): void {
			$file = self::classFile($namespace, $appPath, $class);
			if ($file !== null && is_file($file)) {
				/** @psalm-suppress UnresolvableInclude Runtime path of the app being installed. */
				require_once $file;
			}
		}, true, true);
	}
}
