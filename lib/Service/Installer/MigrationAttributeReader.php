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
 * Says what one migration step changes, from its source text, without ever
 * loading it: a target release's step shares its class name with the
 * installed copy's step, which the running process may already have loaded.
 *
 * It reads, in this order:
 * - the migration attributes of `OCP\Migration\Attributes` (Nextcloud 30 and
 *   later) on the class, each by its short class name with its `table`,
 *   `name` and `description` arguments. An attribute from that namespace this
 *   reader does not know is kept by its name;
 * - when the step has none, the table names `changeSchema()` passes to
 *   `createTable()`, `getTable()`, `dropTable()` and `hasTable()`;
 * - whether `postSchemaChange()` has a body, which is where a step moves data.
 *
 * See design.md D2 of the `safety-upgrade-migration-preview` change.
 *
 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
 * @psalm-api
 */
class MigrationAttributeReader {
	private const ATTRIBUTE_NAMESPACE = 'OCP\\Migration\\Attributes\\';

	/** Attributes whose second positional argument is a column name. */
	private const COLUMN_ATTRIBUTES = ['AddColumn', 'DropColumn', 'ModifyColumn'];

	/** Attributes that describe moving or cleaning data rather than the schema. */
	private const DATA_ATTRIBUTES = ['DataCleansing', 'DataMigrationAttribute'];

	/** Position of the description argument, per attribute kind. */
	private const DESCRIPTION_POSITION_COLUMN = 3;
	private const DESCRIPTION_POSITION_OTHER = 2;

	private const SCHEMA_CALLS = '/->\s*(?:createTable|getTable|dropTable|hasTable)\s*\(\s*([\'"])([^\'"]+)\1/';

	/**
	 * What the step file changes, or null when it cannot be read.
	 *
	 * `describes` is false when the step names no attribute, no table and moves
	 * no data: the page then says the step does not describe what it changes.
	 *
	 * @spec openspec/changes/safety-upgrade-migration-preview/specs/migration-safety/spec.md
	 * @return array{actions: list<array{attribute: string, table: string, name: string, description: string}>, tables: list<string>, dataStep: bool, describes: bool}|null
	 */
	public function read(string $file): ?array {
		$source = self::source($file);
		if ($source === null) {
			return null;
		}

		$actions = $this->actions($source);
		$tables = self::tables($source, $actions);
		$dataStep = self::isDataStep($source, $actions);

		return [
			'actions' => $actions,
			'tables' => $tables,
			'dataStep' => $dataStep,
			'describes' => $actions !== [] || $tables !== [] || $dataStep,
		];
	}

	/** The text of a readable file, else null. */
	private static function source(string $file): ?string {
		if (is_file($file) === false || is_readable($file) === false) {
			return null;
		}

		$source = file_get_contents($file);

		return $source === false ? null : $source;
	}

	/**
	 * The tables the actions name, or without actions the ones `changeSchema()` touches.
	 *
	 * @param list<array{attribute: string, table: string, name: string, description: string}> $actions
	 * @return list<string>
	 */
	private static function tables(string $source, array $actions): array {
		if ($actions !== []) {
			return self::actionTables($actions);
		}

		return self::schemaTables(self::methodBody($source, 'changeSchema') ?? '');
	}

	/**
	 * Whether the step moves data: a `postSchemaChange()` body or a data attribute.
	 *
	 * @param list<array{attribute: string, table: string, name: string, description: string}> $actions
	 */
	private static function isDataStep(string $source, array $actions): bool {
		return self::hasBody(self::methodBody($source, 'postSchemaChange'))
			|| array_intersect(array_column($actions, 'attribute'), self::DATA_ATTRIBUTES) !== [];
	}

	/**
	 * The migration attributes in the source, in order.
	 *
	 * @return list<array{attribute: string, table: string, name: string, description: string}>
	 */
	private function actions(string $source): array {
		$imported = self::importedAttributes($source);
		$actions = [];
		foreach (self::attributeBlocks($source) as [$written, $arguments]) {
			$short = self::migrationAttributeName($written, $imported);
			if ($short === null) {
				continue;
			}

			$actions[] = self::action($short, self::splitArguments($arguments));
		}

		return $actions;
	}

	/**
	 * Short names the source imports from the attribute namespace, by the name it uses for them.
	 *
	 * @return array<string, string>
	 */
	private static function importedAttributes(string $source): array {
		$namespace = preg_quote(self::ATTRIBUTE_NAMESPACE, '/');

		return array_merge(self::singleImports($source, $namespace), self::groupImports($source, $namespace));
	}

	/**
	 * `use Namespace\Name [as Alias];` imports.
	 *
	 * @return array<string, string>
	 */
	private static function singleImports(string $source, string $namespace): array {
		preg_match_all('/^\s*use\s+\\\\?' . $namespace . '(\w+)(?:\s+as\s+(\w+))?\s*;/m', $source, $single, PREG_SET_ORDER);

		return self::byAlias($single);
	}

	/**
	 * `use Namespace\{A, B as C};` imports.
	 *
	 * @return array<string, string>
	 */
	private static function groupImports(string $source, string $namespace): array {
		preg_match_all('/^\s*use\s+\\\\?' . $namespace . '\{([^}]*)\}\s*;/m', $source, $groups);
		$entries = [];
		foreach ($groups[1] ?? [] as $group) {
			foreach (explode(',', $group) as $entry) {
				if (preg_match('/^\s*(\w+)(?:\s+as\s+(\w+))?\s*$/', $entry, $match) === 1) {
					$entries[] = $match;
				}
			}
		}

		return self::byAlias($entries);
	}

	/**
	 * Import matches keyed by the name the source uses: the alias, else the short name.
	 *
	 * @param list<array<int, string>> $matches
	 * @return array<string, string>
	 */
	private static function byAlias(array $matches): array {
		$imported = [];
		foreach ($matches as $match) {
			$imported[($match[2] ?? '') !== '' ? $match[2] : $match[1]] = $match[1];
		}

		return $imported;
	}

	/**
	 * Every `#[...]` block in the source, split into its attributes: the name as written and the raw arguments.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function attributeBlocks(string $source): array {
		$found = [];
		$offset = 0;
		while (($start = strpos($source, '#[', $offset)) !== false) {
			$end = self::closingBracket($source, $start + 1);
			if ($end === null) {
				break;
			}

			array_push($found, ...self::attributesIn(substr($source, $start + 2, $end - $start - 2)));
			$offset = $end + 1;
		}

		return $found;
	}

	/**
	 * The attributes of one `#[...]` block: the name as written and the raw arguments.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private static function attributesIn(string $block): array {
		$found = [];
		foreach (self::splitArguments($block) as $attribute) {
			if (preg_match('/^\s*(\\\\?[A-Za-z_][\w\\\\]*)\s*(?:\((.*)\))?\s*$/s', $attribute, $match) === 1) {
				$found[] = [$match[1], $match[2] ?? ''];
			}
		}

		return $found;
	}

	/**
	 * The short class name of an attribute from the migration attribute namespace, else null.
	 *
	 * @param array<string, string> $imported
	 */
	private static function migrationAttributeName(string $written, array $imported): ?string {
		$written = ltrim($written, '\\');
		if (str_starts_with($written, self::ATTRIBUTE_NAMESPACE)) {
			return substr($written, strlen(self::ATTRIBUTE_NAMESPACE));
		}

		return $imported[$written] ?? null;
	}

	/**
	 * One action from an attribute's short name and its arguments.
	 *
	 * @param list<string> $arguments
	 * @return array{attribute: string, table: string, name: string, description: string}
	 */
	private static function action(string $short, array $arguments): array {
		[$named, $positional] = self::namedAndPositional($arguments);

		$isColumn = in_array($short, self::COLUMN_ATTRIBUTES, true);
		$descriptionAt = $isColumn ? self::DESCRIPTION_POSITION_COLUMN : self::DESCRIPTION_POSITION_OTHER;

		return [
			'attribute' => $short,
			'table' => $named['table'] ?? ($positional[0] ?? ''),
			'name' => $named['name'] ?? ($isColumn ? ($positional[1] ?? '') : ''),
			'description' => $named['description'] ?? ($positional[$descriptionAt] ?? ''),
		];
	}

	/**
	 * The arguments' string values: named ones by name, the others in order.
	 *
	 * @param list<string> $arguments
	 * @return array{0: array<string, string>, 1: list<string>}
	 */
	private static function namedAndPositional(array $arguments): array {
		$named = [];
		$positional = [];
		foreach ($arguments as $argument) {
			if (preg_match('/^\s*(\w+)\s*:(?!:)\s*(.*)$/s', $argument, $match) === 1) {
				$named[$match[1]] = self::stringValue($match[2]);
				continue;
			}

			$positional[] = self::stringValue($argument);
		}

		return [$named, $positional];
	}

	/** The text of a quoted string literal, else an empty string. */
	private static function stringValue(string $argument): string {
		$argument = trim($argument);
		if (preg_match('/^([\'"])(.*)\1$/s', $argument, $match) !== 1) {
			return '';
		}

		return stripcslashes($match[2]);
	}

	/**
	 * Split on commas that sit outside quotes, brackets and parentheses.
	 *
	 * @return list<string>
	 */
	private static function splitArguments(string $text): array {
		$parts = [];
		$start = 0;
		$depth = 0;
		foreach (self::unquoted($text, 0) as $i => $char) {
			$depth += self::depthChange($char, '([', ')]');
			if ($char === ',' && $depth === 0) {
				$parts[] = substr($text, $start, $i - $start);
				$start = $i + 1;
			}
		}

		$parts[] = substr($text, $start);

		return array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
	}

	/** The position of the bracket that closes the one at `$open`, skipping quoted text, else null. */
	private static function closingBracket(string $source, int $open): ?int {
		return self::closing($source, $open, '[', ']');
	}

	/** The position of the delimiter closing the one at `$open`, skipping quoted text, else null. */
	private static function closing(string $source, int $open, string $opening, string $closing): ?int {
		$depth = 0;
		foreach (self::unquoted($source, $open) as $i => $char) {
			$depth += self::depthChange($char, $opening, $closing);
			if ($char === $closing && $depth === 0) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Each character from `$from` on that sits outside a quoted string, keyed by its position.
	 * The quotes themselves and an escaped character inside a string are skipped.
	 *
	 * @return \Generator<int, string>
	 */
	private static function unquoted(string $text, int $from): \Generator {
		$quote = null;
		$length = strlen($text);
		for ($i = $from; $i < $length; $i++) {
			if ($quote !== null) {
				[$quote, $i] = self::insideQuote($text, $i, $quote);
				continue;
			}

			$quote = self::quoteOpenedBy($text[$i]);
			if ($quote === null) {
				yield $i => $text[$i];
			}
		}
	}

	/**
	 * The quote still open after the character at `$i` inside a string, and the position read up to.
	 *
	 * @return array{0: string|null, 1: int}
	 */
	private static function insideQuote(string $text, int $i, string $quote): array {
		if ($text[$i] === '\\') {
			return [$quote, $i + 1];
		}

		return [$text[$i] === $quote ? null : $quote, $i];
	}

	/** The quote a character opens, else null. */
	private static function quoteOpenedBy(string $char): ?string {
		return ($char === '\'' || $char === '"') ? $char : null;
	}

	/** +1 for an opening delimiter, -1 for a closing one, else 0. */
	private static function depthChange(string $char, string $openers, string $closers): int {
		if (str_contains($openers, $char)) {
			return 1;
		}

		return str_contains($closers, $char) ? -1 : 0;
	}

	/** The text between the braces of a method, or null when the class does not declare it. */
	private static function methodBody(string $source, string $method): ?string {
		if (preg_match('/function\s+' . $method . '\s*\(/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
			return null;
		}

		$open = strpos($source, '{', $match[0][1]);
		if ($open === false) {
			return null;
		}

		$close = self::closing($source, $open, '{', '}');

		return $close === null ? null : substr($source, $open + 1, $close - $open - 1);
	}

	/** Whether a method body holds anything besides comments and `return null;`. */
	private static function hasBody(?string $body): bool {
		if ($body === null) {
			return false;
		}

		$code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#', '/#[^\[][^\n]*/', '/\breturn\s+null\s*;/i'], '', $body) ?? '';

		return trim($code) !== '';
	}

	/**
	 * The table names a `changeSchema()` body passes to the schema calls, each once.
	 *
	 * @return list<string>
	 */
	private static function schemaTables(string $body): array {
		preg_match_all(self::SCHEMA_CALLS, $body, $matches);

		return array_values(array_unique($matches[2]));
	}

	/**
	 * The tables the actions name, each once.
	 *
	 * @param list<array{attribute: string, table: string, name: string, description: string}> $actions
	 * @return list<string>
	 */
	private static function actionTables(array $actions): array {
		return array_values(array_unique(array_filter(array_column($actions, 'table'), static fn (string $table): bool => $table !== '')));
	}
}
