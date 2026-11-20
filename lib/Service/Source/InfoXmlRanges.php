<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Source;

/**
 * The server and PHP range an app's `appinfo/info.xml` declares
 * (`<nextcloud min-version max-version>` and `<php min-version max-version>`),
 * each bound '' when it is absent or not a plain dotted number.
 *
 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
 * @psalm-api
 */
final class InfoXmlRanges {
	private const NUMERIC = '/^\d+(\.\d+)*$/';

	/**
	 * @param array{nextcloudMin: string, nextcloudMax: string, phpMin: string, phpMax: string} $bounds
	 */
	private function __construct(
		private array $bounds,
	) {
	}

	/**
	 * The ranges of an info.xml document; no bounds when it is empty or does not parse.
	 *
	 * @spec openspec/changes/inventory-upgrade-readiness/specs/upgrade-readiness/spec.md#requirement-a-forge-releases-ranges-are-read-without-downloading-the-release
	 */
	public static function fromXml(string $infoXml): self {
		$dom = self::document($infoXml);
		if ($dom === null) {
			return self::fromArray([]);
		}

		[$nextcloudMin, $nextcloudMax] = self::bounds($dom, 'nextcloud');
		[$phpMin, $phpMax] = self::bounds($dom, 'php');

		return self::fromArray(['nextcloudMin' => $nextcloudMin, 'nextcloudMax' => $nextcloudMax, 'phpMin' => $phpMin, 'phpMax' => $phpMax]);
	}

	/**
	 * The ranges as stored by {@see toArray()}; a missing, non-string or non-numeric bound reads ''.
	 *
	 * @param array<array-key, mixed> $stored
	 */
	public static function fromArray(array $stored): self {
		return new self([
			'nextcloudMin' => self::stored($stored['nextcloudMin'] ?? null),
			'nextcloudMax' => self::stored($stored['nextcloudMax'] ?? null),
			'phpMin' => self::stored($stored['phpMin'] ?? null),
			'phpMax' => self::stored($stored['phpMax'] ?? null),
		]);
	}

	/**
	 * @return array{nextcloudMin: string, nextcloudMax: string, phpMin: string, phpMax: string}
	 */
	public function toArray(): array {
		return $this->bounds;
	}

	/** The server range as a platform spec such as `>=30 <=32`, or '' when it declares none. */
	public function platformSpec(): string {
		return self::spec($this->bounds['nextcloudMin'], $this->bounds['nextcloudMax']);
	}

	/** The PHP range as a spec such as `>=8.1 <=8.3`, or '' when it declares none. */
	public function phpSpec(): string {
		return self::spec($this->bounds['phpMin'], $this->bounds['phpMax']);
	}

	private static function document(string $infoXml): ?\DOMDocument {
		if ($infoXml === '') {
			return null;
		}

		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		try {
			$loaded = $dom->loadXML($infoXml, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		return $loaded ? $dom : null;
	}

	/**
	 * The numeric min-version and max-version of the first element with this name.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function bounds(\DOMDocument $dom, string $element): array {
		$node = $dom->getElementsByTagName($element)->item(0);
		if (!$node instanceof \DOMElement) {
			return ['', ''];
		}

		return [self::numeric($node->getAttribute('min-version')), self::numeric($node->getAttribute('max-version'))];
	}

	private static function numeric(string $version): string {
		$version = trim($version);

		return preg_match(self::NUMERIC, $version) === 1 ? $version : '';
	}

	private static function stored(mixed $bound): string {
		return is_string($bound) ? self::numeric($bound) : '';
	}

	private static function spec(string $min, string $max): string {
		$bounds = array_filter([$min === '' ? '' : '>=' . $min, $max === '' ? '' : '<=' . $max]);

		return implode(' ', $bounds);
	}
}
