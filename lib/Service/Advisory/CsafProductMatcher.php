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
		foreach ($this->nextcloudProducts(document: $document) as $product) {
			foreach ($this->hits(product: $product, affected: $affected) as [$target, $range, $productId]) {
				$key = $target . "\n" . ($range ?? '');
				$found[$key] ??= ['target' => $target, 'range' => $range, 'productIds' => []];
				$found[$key]['productIds'] = array_values(array_unique([...$found[$key]['productIds'], $productId]));
			}
		}

		return array_values($found);
	}

	/**
	 * The `product_name` branches under every Nextcloud vendor branch.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array<array-key, mixed> $document The CSAF document.
	 * @return list<array<array-key, mixed>>
	 */
	private function nextcloudProducts(array $document): array {
		$products = [];
		foreach ($this->branches(node: $document['product_tree'] ?? null) as $vendor) {
			if ($this->isNextcloudVendor(branch: $vendor)) {
				array_push($products, ...$this->productNames(node: $vendor));
			}
		}

		return $products;
	}

	/**
	 * Whether a branch is the vendor Nextcloud (or Nextcloud GmbH).
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array<array-key, mixed> $branch The branch.
	 */
	private function isNextcloudVendor(array $branch): bool {
		return ($branch['category'] ?? '') === 'vendor' && in_array($this->normalise((string)($branch['name'] ?? '')), self::VENDORS, true);
	}

	/**
	 * The known-affected products of one product branch, as target, range and
	 * product id; none when the name resolves to nothing or to a client.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array<array-key, mixed> $product The product_name branch.
	 * @param array<string, true> $affected The known-affected product ids.
	 * @return list<array{0: string, 1: ?string, 2: string}>
	 */
	private function hits(array $product, array $affected): array {
		$target = $this->packages->resolve($this->withoutVendor(name: (string)($product['name'] ?? '')));
		if ($target === null) {
			return [];
		}

		$hits = [];
		foreach ($this->leaves(branch: $product) as [$range, $productId]) {
			if (isset($affected[$productId])) {
				$hits[] = [$target, $range, $productId];
			}
		}

		return $hits;
	}

	/**
	 * The product ids any vulnerability lists as known affected.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array<array-key, mixed> $document The CSAF document.
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
	 * One vulnerability's known-affected product ids.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @return list<string>
	 */
	private static function affectedIdsOf(mixed $vulnerability): array {
		$ids = is_array($vulnerability) ? (array)($vulnerability['product_status']['known_affected'] ?? []) : [];

		return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));
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
		$own = (string)($branch['product']['product_id'] ?? '');
		$leaves = $own !== '' ? [[null, $own]] : [];
		foreach ($this->branches(node: $branch) as $child) {
			$leaf = self::leaf(child: $child);
			if ($leaf !== null) {
				$leaves[] = $leaf;
			}
		}
		return $leaves;
	}

	/**
	 * A child branch's range and product id, or null when it names no product.
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array<array-key, mixed> $child The branch.
	 * @return array{0: ?string, 1: string}|null
	 */
	private static function leaf(array $child): ?array {
		$id = (string)($child['product']['product_id'] ?? '');

		return $id === '' ? null : [self::rangeOf(child: $child), $id];
	}

	/**
	 * The range a branch covers: its `vers:` name for a version range, an
	 * exact version as `vers:generic/=<version>`, otherwise unstated (null).
	 *
	 * @spec openspec/changes/advisories-ncsc-feed/design.md
	 * @param array<array-key, mixed> $child The branch.
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
