<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Settings;

use OCA\Versioniq\Service\Connection\ConnectionReportService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of the Integrations tab. Nothing in Versioniq reads it at runtime, so a
 * broken file fails nowhere in this repo: integriq skips it whole and the tab
 * goes empty on some other instance. Every assertion here is a way that file
 * could go wrong without a sound.
 *
 * The rules mirror integriq's `lib/Settings/connections.schema.json` on
 * `development` field for field, including the hydra#673 amendments
 * (`adapter.jsonPath`, `adapter.simulatedValues`, `reportedOnly`). That schema
 * is not a dependency of this repo, so the rules are restated here. The file
 * was also validated against the schema itself, fetched from integriq
 * `development` with `gh api`, when this test was written.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-versioniq-conn-001-versioniq-declares-its-outside-connections-in-one-static-file
 */
final class ConnectionsDeclarationTest extends TestCase {
	/** @var array<string, string> The fields the schema allows on one connection, with their JSON type. */
	private const FIELD_TYPES = [
		'key' => 'string',
		'title' => 'string',
		'description' => 'string',
		'order' => 'integer',
		'settingsUrl' => 'string',
		'requiredConfig' => 'array',
		'adapter' => 'array',
		'reportedOnly' => 'boolean',
		'available' => 'boolean',
		'unavailableMessage' => 'string',
		'unconfiguredMessage' => 'string',
		'sourceTemplate' => 'string',
	];

	/** @var list<string> The three connections, in page order. */
	private const KEYS = ['appstore', 'github', 'advisories'];

	/** @var list<string> Proper nouns a sentence-case title may still capitalise. */
	private const PROPER_NOUNS = ['App', 'Store', 'GitHub', 'Nextcloud'];

	private function root(): string {
		return dirname(__DIR__, 3);
	}

	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		self::assertIsString($raw, 'lib/Settings/connections.json must exist');

		return $raw;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		return $decoded;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function connections(): array {
		$connections = $this->declaration()['connections'];
		self::assertIsArray($connections);

		return array_values($connections);
	}

	public function testTheFileNamesThisApp(): void {
		// Integriq refuses a file whose `app` differs from the app it was read from.
		$declaration = $this->declaration();
		$infoXml = simplexml_load_file($this->root() . '/appinfo/info.xml');

		self::assertNotFalse($infoXml);
		self::assertSame((string)$infoXml->id, $declaration['app']);
		self::assertSame('versioniq', $declaration['app']);
		self::assertSame(['app', 'connections'], array_keys($declaration));
	}

	public function testTheKeysAreUniqueAndTheReportedOnes(): void {
		// A report for a key the file does not declare is refused by integriq
		// with only a warning in its log.
		$keys = array_column($this->connections(), 'key');

		self::assertSame(array_values(array_unique($keys)), $keys);
		self::assertSame(self::KEYS, $keys);
		self::assertSame(
			self::KEYS,
			[ConnectionReportService::KEY_APPSTORE, ConnectionReportService::KEY_GITHUB, ConnectionReportService::KEY_ADVISORIES],
		);
	}

	public function testNoRowNamesTheRetiredForge(): void {
		// Conduction left Codeberg. The forge code stays; a row would advertise it.
		foreach ($this->connections() as $connection) {
			self::assertStringNotContainsStringIgnoringCase('codeberg', json_encode($connection, JSON_THROW_ON_ERROR));
		}
		self::assertSame('github', ConnectionReportService::REPORTED_FORGE);
	}

	public function testEveryEntryHasTheShapeIntegriqValidates(): void {
		$previousOrder = 0;
		foreach ($this->connections() as $connection) {
			$key = (string)$connection['key'];

			self::assertSame([], array_values(array_diff(array_keys($connection), array_keys(self::FIELD_TYPES))), $key . ' carries a field the schema does not allow');
			foreach ($connection as $field => $value) {
				self::assertSame(self::FIELD_TYPES[$field], $this->jsonType($value), $key . '.' . $field);
			}

			self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key);
			self::assertNotSame('', trim((string)($connection['title'] ?? '')), $key . ' has no title');
			self::assertStringStartsWith('/', (string)($connection['settingsUrl'] ?? '/'), $key);
			self::assertGreaterThan($previousOrder, $connection['order'], $key . ' breaks the page order');
			$previousOrder = $connection['order'];
		}
	}

	public function testEveryRowIsReportedOnly(): void {
		// Every override is optional: an empty key is the working default. A
		// requiredConfig list could only call an unused override configured,
		// and none of the three has a mock adapter for rule 3.
		foreach ($this->connections() as $connection) {
			$key = (string)$connection['key'];
			self::assertTrue($connection['reportedOnly'] ?? false, $key);
			self::assertArrayNotHasKey('requiredConfig', $connection, $key);
			self::assertArrayNotHasKey('adapter', $connection, $key);
			self::assertStringStartsWith('Not checked yet.', (string)($connection['unconfiguredMessage'] ?? ''), $key);
		}
	}

	public function testNoTextBreaksTheVoiceRules(): void {
		self::assertStringNotContainsString("\u{2014}", $this->raw());
		self::assertStringNotContainsString('--', $this->raw());

		foreach ($this->connections() as $connection) {
			$words = explode(' ', (string)$connection['title']);
			foreach (array_slice($words, 1) as $word) {
				if (in_array($word, self::PROPER_NOUNS, true)) {
					continue;
				}
				self::assertSame(mb_strtolower($word), $word, $connection['key'] . ' title is not sentence case');
			}
		}
	}

	public function testEverySettingsLinkPointsAtAnAnchorThePageRenders(): void {
		// The admin section is `versioniq` (Admin::getSection()). An anchor that
		// no element carries opens the settings page at the top.
		$admin = (string)file_get_contents($this->root() . '/lib/Settings/Admin.php');
		self::assertStringContainsString('return Application::APP_ID;', $admin);

		$sources = '';
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/src'));
		foreach ($files as $file) {
			if ($file->isFile() && $file->getExtension() === 'vue') {
				$sources .= (string)file_get_contents($file->getPathname());
			}
		}

		$linked = 0;
		foreach ($this->connections() as $connection) {
			if (!isset($connection['settingsUrl'])) {
				continue;
			}
			$url = (string)$connection['settingsUrl'];
			self::assertMatchesRegularExpression('~^/settings/admin/versioniq#section-[a-z0-9-]+$~', $url, $connection['key'] . ' links somewhere other than a versioniq admin anchor');

			$anchor = substr($url, strpos($url, '#') + 1);
			self::assertSame(1, substr_count($sources, 'id="' . $anchor . '"'), $connection['key'] . ' links to #' . $anchor . ', and no component carries that id once');
			$linked++;
		}

		// The App Store has no admin screen, so two of three rows link.
		self::assertSame(2, $linked);
	}

	private function jsonType(mixed $value): string {
		return match (true) {
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_string($value) => 'string',
			is_array($value) => 'array',
			default => get_debug_type($value),
		};
	}
}
