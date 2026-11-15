<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\Versioniq\Service\Source\SourceBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every accepted and refused outcome of the SourceBinding constructor's
 * validation, including the exact refusal message and the order the checks
 * run in (the first failing check decides the message).
 *
 * @spec openspec/specs/external-sources/spec.md
 */
final class SourceBindingValidationGoldenTest extends TestCase {
	/**
	 * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: ?string}>
	 */
	public static function cases(): iterable {
		$ok = ['owner' => 'ConductionNL', 'repo' => 'versioniq'];

		yield 'appstore accepted' => ['appstore', [], null];
		yield 'appstore ignores release config' => ['appstore', ['owner' => '../x', 'forge' => 'gitlab'], null];
		yield 'unknown kind' => ['svn', $ok, 'Unknown source kind: svn'];
		yield 'empty kind' => ['', [], 'Unknown source kind: '];
		yield 'release accepted' => ['github-release', $ok, null];
		yield 'release with dots, dashes and underscores' => ['github-release', ['owner' => 'a.b-c_d', 'repo' => 'R.e-p_0'], null];
		yield 'release forge github' => ['github-release', $ok + ['forge' => 'github'], null];
		yield 'release forge forgejo' => ['github-release', $ok + ['forge' => 'forgejo'], null];
		yield 'release forge codeberg' => ['github-release', $ok + ['forge' => 'codeberg'], null];
		yield 'owner missing' => ['github-release', ['repo' => 'x'], 'github-release binding requires non-empty owner'];
		yield 'owner null' => ['github-release', ['owner' => null, 'repo' => 'x'], 'github-release binding requires non-empty owner'];
		yield 'owner not a string' => ['github-release', ['owner' => 5, 'repo' => 'x'], 'github-release binding requires non-empty owner'];
		yield 'owner empty' => ['github-release', ['owner' => '', 'repo' => 'x'], 'github-release binding requires non-empty owner'];
		yield 'owner and repo missing reports owner first' => ['github-release', [], 'github-release binding requires non-empty owner'];
		yield 'repo missing' => ['github-release', ['owner' => 'x'], 'github-release binding requires non-empty repo'];
		yield 'repo not a string' => ['github-release', ['owner' => 'x', 'repo' => ['a']], 'github-release binding requires non-empty repo'];
		yield 'repo empty' => ['github-release', ['owner' => 'x', 'repo' => ''], 'github-release binding requires non-empty repo'];
		yield 'repo empty beats bad owner charset' => ['github-release', ['owner' => 'a/b', 'repo' => ''], 'github-release binding requires non-empty repo'];
		yield 'owner path traversal' => ['github-release', ['owner' => '..//x', 'repo' => 'y'], 'github-release owner contains invalid characters'];
		yield 'owner with slash' => ['github-release', ['owner' => 'a/b', 'repo' => 'y'], 'github-release owner contains invalid characters'];
		yield 'owner with space' => ['github-release', ['owner' => 'a b', 'repo' => 'y'], 'github-release owner contains invalid characters'];
		yield 'owner bad beats repo bad' => ['github-release', ['owner' => 'a b', 'repo' => 'c/d'], 'github-release owner contains invalid characters'];
		yield 'repo path traversal' => ['github-release', ['owner' => 'x', 'repo' => '../../etc'], 'github-release repo contains invalid characters'];
		yield 'repo with star' => ['github-release', ['owner' => 'x', 'repo' => 'a*'], 'github-release repo contains invalid characters'];
		yield 'repo bad beats forge bad' => ['github-release', ['owner' => 'x', 'repo' => 'a*', 'forge' => 'gitlab'], 'github-release repo contains invalid characters'];
		yield 'unknown forge' => ['github-release', $ok + ['forge' => 'gitlab'], 'github-release binding has an unknown forge'];
		yield 'forge empty string' => ['github-release', $ok + ['forge' => ''], 'github-release binding has an unknown forge'];
		yield 'forge not a string' => ['github-release', $ok + ['forge' => 1], 'github-release binding has an unknown forge'];
		yield 'forge case sensitive' => ['github-release', $ok + ['forge' => 'GitHub'], 'github-release binding has an unknown forge'];
		yield 'forge null counts as absent' => ['github-release', $ok + ['forge' => null], null];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	#[DataProvider('cases')]
	public function testConstructorOutcome(string $kind, array $config, ?string $refusal): void {
		try {
			$binding = new SourceBinding($kind, $config);
		} catch (InvalidArgumentException $e) {
			self::assertSame($refusal, $e->getMessage(), 'refused, expected ' . ($refusal === null ? 'acceptance' : 'another refusal'));

			return;
		}

		self::assertNull($refusal, 'accepted, expected refusal: ' . (string)$refusal);
		self::assertSame($kind, $binding->kind);
		self::assertSame($config, $binding->config);
	}
}
