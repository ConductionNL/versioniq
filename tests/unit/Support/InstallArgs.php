<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Support;

use OCA\Versioniq\Service\InstallerService;
use PHPUnit\Framework\Constraint\IsEqual;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Calls and matches InstallerService::installAppVersion() by its nine install
 * values (app, version, debug, source, override pin, pin, accept new SHA,
 * allow downgrade, dry run), whether the method takes them one by one or as
 * one InstallRequest. A dry run left out follows debug, as it always has.
 */
final class InstallArgs {
	private const NAMES = ['appId', 'targetVersion', 'includeDebug', 'sourceOverride', 'overridePin', 'pinRequested', 'acceptNewSha', 'allowDowngrade', 'dryRun'];
	private const DEFAULTS = ['sourceOverride' => null, 'overridePin' => null, 'pinRequested' => false, 'acceptNewSha' => false, 'allowDowngrade' => false, 'dryRun' => null];
	private const REQUEST = 'OCA\\Versioniq\\Service\\Installer\\InstallRequest';

	/**
	 * @return array<string, mixed>
	 */
	public static function call(InstallerService $service, mixed ...$args): array {
		if (!self::takesRequest()) {
			return $service->installAppVersion(...$args);
		}

		return $service->installAppVersion(self::request(self::named($args)));
	}

	/**
	 * with() arguments for installAppVersion() matching the nine values.
	 *
	 * @return list<mixed>
	 */
	public static function expected(mixed ...$args): array {
		if (!self::takesRequest()) {
			return array_values($args);
		}

		return [new IsEqual(self::request(self::named($args)))];
	}

	/**
	 * The nine values by name, from either call shape.
	 *
	 * @param array<array-key, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function named(array $args): array {
		$first = $args[0] ?? null;
		if (is_object($first) && $first::class === self::REQUEST) {
			return [
				'appId' => $first->target->appId,
				'targetVersion' => $first->target->version,
				'includeDebug' => $first->options->includeDebug,
				'sourceOverride' => $first->target->sourceOverride,
				'overridePin' => $first->pinChoice->overridePin,
				'pinRequested' => $first->pinChoice->pinRequested,
				'acceptNewSha' => $first->options->acceptNewSha,
				'allowDowngrade' => $first->options->allowDowngrade,
				'dryRun' => $first->options->dryRun,
			];
		}

		$named = self::DEFAULTS;
		foreach ($args as $key => $value) {
			$named[is_int($key) ? self::NAMES[$key] : $key] = $value;
		}

		return $named;
	}

	/**
	 * @param array<string, mixed> $v
	 */
	private static function request(array $v): object {
		$target = 'OCA\\Versioniq\\Service\\Installer\\InstallTarget';
		$options = 'OCA\\Versioniq\\Service\\Installer\\InstallOptions';
		$pin = 'OCA\\Versioniq\\Service\\Installer\\PinChoice';
		$request = self::REQUEST;

		return new $request(
			new $target($v['appId'], $v['targetVersion'], $v['sourceOverride']),
			new $options($v['includeDebug'], $v['dryRun'] ?? $v['includeDebug'], $v['acceptNewSha'], $v['allowDowngrade']),
			new $pin($v['overridePin'], $v['pinRequested']),
		);
	}

	private static function takesRequest(): bool {
		$type = (new ReflectionMethod(InstallerService::class, 'installAppVersion'))->getParameters()[0]->getType();

		return $type instanceof ReflectionNamedType && $type->getName() === self::REQUEST;
	}
}
