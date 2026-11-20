<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Safety;

use UnexpectedValueException;

/**
 * One DER element (tag, length, content) inside a byte string, read just far
 * enough to walk a certificate revocation list: no decoding of the content
 * beyond what {@see CrlDocument} asks for.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */
final class DerElement {
	public const TAG_INTEGER = 0x02;
	public const TAG_BIT_STRING = 0x03;
	public const TAG_OCTET_STRING = 0x04;
	public const TAG_OID = 0x06;
	public const TAG_UTC_TIME = 0x17;
	public const TAG_GENERALIZED_TIME = 0x18;
	public const TAG_SEQUENCE = 0x30;
	public const TAG_CONTEXT_0 = 0xA0;

	private function __construct(
		public readonly int $tag,
		/** Where the element starts, its tag byte. */
		public readonly int $offset,
		/** Where its content starts. */
		public readonly int $contentOffset,
		/** Where the next element starts. */
		public readonly int $end,
		private readonly string $der,
	) {
	}

	/**
	 * The element that starts at $offset of $der.
	 *
	 * @throws UnexpectedValueException when the bytes are not a whole DER element
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public static function at(string $der, int $offset = 0): self {
		if ($offset + 2 > strlen($der)) {
			throw new UnexpectedValueException('Truncated DER element.');
		}

		$first = ord($der[$offset + 1]);
		$lengthBytes = $first > 0x7F ? $first & 0x7F : 0;
		if ($lengthBytes > 4 || $offset + 2 + $lengthBytes > strlen($der)) {
			throw new UnexpectedValueException('Unsupported DER length.');
		}

		$length = $lengthBytes === 0 ? $first : (int)hexdec(bin2hex(substr($der, $offset + 2, $lengthBytes)));
		$contentOffset = $offset + 2 + $lengthBytes;
		if ($contentOffset + $length > strlen($der)) {
			throw new UnexpectedValueException('Truncated DER content.');
		}

		return new self(ord($der[$offset]), $offset, $contentOffset, $contentOffset + $length, $der);
	}

	/**
	 * The content bytes.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function content(): string {
		return substr($this->der, $this->contentOffset, $this->end - $this->contentOffset);
	}

	/**
	 * The whole element, tag and length included.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function encoded(): string {
		return substr($this->der, $this->offset, $this->end - $this->offset);
	}

	/**
	 * The elements a constructed element (a SEQUENCE, a tagged wrapper) holds.
	 *
	 * @return list<self>
	 * @throws UnexpectedValueException when the content is not a run of whole elements
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function children(): array {
		$children = [];
		$offset = $this->contentOffset;
		while ($offset < $this->end) {
			$child = self::at($this->der, $offset);
			if ($child->end > $this->end) {
				throw new UnexpectedValueException('DER element runs past its parent.');
			}
			$children[] = $child;
			$offset = $child->end;
		}

		return $children;
	}

	/**
	 * The child at $index, required to carry $tag.
	 *
	 * @throws UnexpectedValueException when there is no such child
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function child(int $index, int $tag): self {
		$child = $this->children()[$index] ?? null;
		if ($child === null || $child->tag !== $tag) {
			throw new UnexpectedValueException(sprintf('Expected DER tag 0x%02X at position %d.', $tag, $index));
		}

		return $child;
	}

	/**
	 * An INTEGER's value as upper-case hex without leading zeros, the form
	 * serial numbers and CRL numbers are compared in.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function integerHex(): string {
		return self::normalHex(bin2hex($this->content()));
	}

	/**
	 * Hex as upper case without leading zeros; '0' for zero.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public static function normalHex(string $hex): string {
		$trimmed = ltrim(strtoupper($hex), '0');

		return $trimmed === '' ? '0' : $trimmed;
	}
}
