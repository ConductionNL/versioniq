<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Advisory;

/**
 * Finds the Nextcloud products a CSAF 2.0 advisory is about (advisories-ncsc-feed D3).
 *
 * Walks `product_tree.branches` and keeps branches under a `vendor` named
 * Nextcloud. Each `product_name` beneath it loses a leading "Nextcloud" and is
 * resolved through {@see AdvisoryPackageMap}: "Nextcloud Server" is the server,
 * "Nextcloud Talk" resolves through the display-name index to `spreed`, a
 * client or an unknown name is dropped, as in the Nextcloud feed. A product
 * counts only when a vulnerability lists one of its product ids under
 * `product_status.known_affected`.
 *
 * @spec openspec/changes/advisories-ncsc-feed/design.md
 * @psalm-api
 */
class CsafProductMatcher {

	/** @var list<string> Normalised vendor names that are Nextcloud. */
	private const VENDORS = ['nextcloud', 'nextcloudgmbh'];

	public function __construct(
		private AdvisoryPackageMap $packages,
	) {
	}

	/**
	 * The targets this document is about, each with its version range and product ids.
	 *
	 * @param array<array-key, mixed> $document The CSAF document.
	 *
	 * @return list<array{target: string, range: ?string, productIds: list<string>}> The targets.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 */
	public function targets(array $document): array {
		$affected = $this->knownAffected(document: $document);
		if ($affected === []) {
			return [];
		}

		$found = [];
		foreach ($this->nextcloudVendors(document: $document) as $vendor) {
			foreach ($this->productNames(node: $vendor) as $product) {
				$this->collect(found: $found, product: $product, affected: $affected);
			}
		}

		return array_values($found);
	}

	/**
	 * The vendor branches of the product tree that are Nextcloud.
	 *
	 * @param array<string, mixed> $document The CSAF document.
	 *
	 * @return list<array<string, mixed>> The vendor branches, in tree order.
	 */
	private function nextcloudVendors(array $document): array {
		return array_values(array_filter(
			$this->branches(node: $document['product_tree'] ?? null),
			fn (array $branch): bool => ($branch['category'] ?? '') === 'vendor'
				&& in_array($this->normalise((string)($branch['name'] ?? '')), self::VENDORS, true)
		));
	}

	/**
	 * Add the known-affected leaves of one product to the targets found.
	 *
	 * @param array<string, array{target: string, range: string|null, productIds: list<string>}> $found The targets so far, by target and range.
	 * @param array<string, mixed> $product The product_name branch.
	 * @param array<string, true> $affected The known-affected product ids.
	 *
	 * @return void
	 */
	private function collect(array &$found, array $product, array $affected): void {
		$target = $this->packages->resolve($this->withoutVendor(name: (string)($product['name'] ?? '')));
		if ($target === null) {
			return;
		}

		foreach ($this->leaves(branch: $product) as [$range, $productId]) {
			if (isset($affected[$productId]) === true) {
				self::add(found: $found, target: $target, range: $range, productId: $productId);
			}
		}
	}

	/**
	 * Record one affected product id under its target and range, once.
	 *
	 * @param array<string, array{target: string, range: string|null, productIds: list<string>}> $found The targets so far.
	 * @param string $target The resolved target.
	 * @param string|null $range The VERS range, or null for every version.
	 * @param string $productId The product id.
	 *
	 * @return void
	 */
	private static function add(array &$found, string $target, ?string $range, string $productId): void {
		$key = $target . "\n" . ($range ?? '');
		$found[$key] ??= ['target' => $target, 'range' => $range, 'productIds' => []];
		if (in_array($productId, $found[$key]['productIds'], true) === false) {
			$found[$key]['productIds'][] = $productId;
		}
	}

	/**
	 * The product ids any vulnerability lists as known affected.
	 *
	 * @param array<array-key, mixed> $document The CSAF document.
	 *
	 * @return array<string, true> The ids.
	 */
	private function knownAffected(array $document): array {
		$ids = [];
		foreach ((array)($document['vulnerabilities'] ?? []) as $vulnerability) {
			foreach (self::affectedIdsOf(vulnerability: $vulnerability) as $id) {
				$ids[$id] = true;
			}
		}

		return $ids;
	}

	/**
	 * The known-affected product ids of one vulnerability.
	 *
	 * @param mixed $vulnerability The vulnerability entry.
	 *
	 * @return list<string> The non-empty ids; none when the entry is not an object.
	 */
	private static function affectedIdsOf(mixed $vulnerability): array {
		if (is_array($vulnerability) === false) {
			return [];
		}

		return array_values(array_filter(
			(array)($vulnerability['product_status']['known_affected'] ?? []),
			static fn (mixed $id): bool => is_string($id) && $id !== ''
		));
	}

	/**
	 * Every branch under a node, depth first.
	 *
	 * @param mixed $node A product tree or a branch.
	 *
	 * @return list<array<array-key, mixed>> The branches.
	 */
	private function branches(mixed $node): array {
		if (!is_array($node)) {
			return [];
		}
		$all = [];
		foreach ((array)($node['branches'] ?? []) as $branch) {
			if (is_array($branch)) {
				$all[] = $branch;
				array_push($all, ...$this->branches(node: $branch));
			}
		}
		return $all;
	}

	/**
	 * The `product_name` branches under a vendor branch.
	 *
	 * @param array<array-key, mixed> $node The vendor branch.
	 *
	 * @return list<array<array-key, mixed>> The product branches.
	 */
	private function productNames(array $node): array {
		return array_values(array_filter(
			$this->branches(node: $node),
			static fn (array $branch): bool => ($branch['category'] ?? '') === 'product_name'
		));
	}

	/**
	 * The products under a product_name branch, with the range they cover.
	 *
	 * A `product_version_range` branch gives its `vers:` name; an exact
	 * `product_version` reads as `vers:generic/=<version>`; a product on the
	 * product_name branch itself covers an unstated range (null).
	 *
	 * @param array<array-key, mixed> $branch The product_name branch.
	 *
	 * @return list<array{0: ?string, 1: string}> Pairs of range and product id.
	 */
	private function leaves(array $branch): array {
		$leaves = [];
		$own = (string)($branch['product']['product_id'] ?? '');
		if ($own !== '') {
			$leaves[] = [null, $own];
		}

		foreach ($this->branches(node: $branch) as $child) {
			$id = (string)($child['product']['product_id'] ?? '');
			if ($id !== '') {
				$leaves[] = [self::rangeOf(child: $child), $id];
			}
		}

		return $leaves;
	}

	/**
	 * The VERS range a version branch names, or null.
	 *
	 * @param array<string, mixed> $child The branch.
	 *
	 * @return string|null A range as written, `vers:generic/=<version>` for one version, null otherwise.
	 */
	private static function rangeOf(array $child): ?string {
		$name = trim((string)($child['name'] ?? ''));
		if ($name === '') {
			return null;
		}

		return match ($child['category'] ?? '') {
			'product_version_range' => $name,
			'product_version' => 'vers:generic/=' . $name,
			default => null,
		};
	}

	/**
	 * The product name without a leading "Nextcloud".
	 *
	 * @param string $name The product name.
	 *
	 * @return string The rest.
	 */
	private function withoutVendor(string $name): string {
		return trim((string)preg_replace('/^\s*nextcloud\b\s*/i', '', $name));
	}

	/**
	 * Lower case, letters and digits only.
	 *
	 * @param string $value The text.
	 *
	 * @return string The key.
	 */
	private function normalise(string $value): string {
		return (string)preg_replace('/[^a-z0-9]/', '', strtolower($value));
	}
}
