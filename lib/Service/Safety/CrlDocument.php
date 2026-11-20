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
 * One certificate revocation list, read from PEM or DER: its CRL number, the
 * serial numbers it revokes, and whether its signature validates against a
 * certificate (safety-withdrawn-and-revoked, design D1).
 *
 * Serial and CRL numbers are kept as upper-case hex without leading zeros,
 * so they compare without a big-number library.
 *
 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
 */
final class CrlDocument {
	/** OID 2.5.29.20, the CRL number extension, as DER content bytes in hex. */
	private const OID_CRL_NUMBER = '551d14';

	/** Signature algorithm OIDs (DER content bytes in hex) to the digest OpenSSL verifies with. */
	private const SIGNATURE_DIGESTS = [
		'2a864886f70d010105' => OPENSSL_ALGO_SHA1,
		'2a864886f70d01010b' => OPENSSL_ALGO_SHA256,
		'2a864886f70d01010c' => OPENSSL_ALGO_SHA384,
		'2a864886f70d01010d' => OPENSSL_ALGO_SHA512,
		'2a8648ce3d040302' => OPENSSL_ALGO_SHA256,
		'2a8648ce3d040303' => OPENSSL_ALGO_SHA384,
		'2a8648ce3d040304' => OPENSSL_ALGO_SHA512,
	];

	/** The list in PEM, as phpseclib's loadCRL() reads it. */
	public readonly string $pem;

	/** The CRL number, null when the list carries none. */
	public readonly ?string $number;

	/** @var array<string, true> The revoked serial numbers, as keys. */
	private readonly array $revoked;

	/** The signed TBSCertList, tag and length included. */
	private readonly string $signedPart;

	private readonly string $signatureAlgorithm;

	private readonly string $signature;

	/**
	 * Reads a list from its DER bytes.
	 *
	 * @throws UnexpectedValueException when the bytes are not a certificate revocation list
	 */
	private function __construct(string $der) {
		$list = DerElement::at($der);
		if ($list->tag !== DerElement::TAG_SEQUENCE || $list->end !== strlen($der)) {
			throw new UnexpectedValueException('Not a certificate revocation list.');
		}

		$signedPart = $list->child(0, DerElement::TAG_SEQUENCE);
		[$this->revoked, $this->number] = self::readSignedPart($signedPart);
		$this->pem = self::pem($der);
		$this->signedPart = $signedPart->encoded();
		$this->signatureAlgorithm = bin2hex($list->child(1, DerElement::TAG_SEQUENCE)->child(0, DerElement::TAG_OID)->content());
		$this->signature = substr($list->child(2, DerElement::TAG_BIT_STRING)->content(), 1);
	}

	/**
	 * Reads a list from PEM or DER.
	 *
	 * @throws UnexpectedValueException when the text is not a certificate revocation list
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public static function parse(string $text): self {
		return new self(self::der($text));
	}

	/**
	 * Whether the list revokes the certificate with this serial number (hex, any case, leading zeros allowed).
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function revokes(string $serialHex): bool {
		return isset($this->revoked[DerElement::normalHex($serialHex)]);
	}

	/**
	 * Whether one of the PEM certificates in $certificates signed this list.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function isSignedByOneOf(string $certificates): bool {
		$digest = self::SIGNATURE_DIGESTS[$this->signatureAlgorithm] ?? null;
		if ($digest === null) {
			return false;
		}

		foreach (self::certificatesIn($certificates) as $certificate) {
			$key = openssl_pkey_get_public($certificate);
			if ($key !== false && openssl_verify($this->signedPart, $this->signature, $key, $digest) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether this list carries a higher CRL number than $other. A list
	 * without a number is never newer.
	 *
	 * @spec openspec/changes/safety-withdrawn-and-revoked/specs/withdrawn-and-revoked/spec.md#requirement-installed-apps-are-rechecked-against-a-current-revocation-list
	 */
	public function isNewerThan(self $other): bool {
		if ($this->number === null) {
			return false;
		}
		if ($other->number === null) {
			return true;
		}
		if (strlen($this->number) !== strlen($other->number)) {
			return strlen($this->number) > strlen($other->number);
		}

		return strcmp($this->number, $other->number) > 0;
	}

	/**
	 * The revoked serials and the CRL number of a TBSCertList (RFC 5280 5.1):
	 * an optional version, the signature algorithm, the issuer, one or two
	 * times, then the optional revoked list and the optional extensions.
	 *
	 * @return array{0: array<string, true>, 1: ?string}
	 */
	private static function readSignedPart(DerElement $signedPart): array {
		$fields = $signedPart->children();
		$index = ($fields[0] ?? null)?->tag === DerElement::TAG_INTEGER ? 3 : 2;
		while (in_array(($fields[$index] ?? null)?->tag, [DerElement::TAG_UTC_TIME, DerElement::TAG_GENERALIZED_TIME], true)) {
			$index++;
		}

		$revoked = [];
		if (($fields[$index] ?? null)?->tag === DerElement::TAG_SEQUENCE) {
			$revoked = self::revokedSerials($fields[$index]);
			$index++;
		}
		$extensions = ($fields[$index] ?? null)?->tag === DerElement::TAG_CONTEXT_0 ? $fields[$index] : null;

		return [$revoked, $extensions === null ? null : self::crlNumber($extensions)];
	}

	/**
	 * The serial numbers of the revoked-certificates sequence, as keys.
	 *
	 * @return array<string, true>
	 */
	private static function revokedSerials(DerElement $entries): array {
		$serials = [];
		foreach ($entries->children() as $entry) {
			$serials[$entry->child(0, DerElement::TAG_INTEGER)->integerHex()] = true;
		}

		return $serials;
	}

	/**
	 * The CRL number among the list's extensions, or null when it has none.
	 */
	private static function crlNumber(DerElement $wrapper): ?string {
		foreach ($wrapper->child(0, DerElement::TAG_SEQUENCE)->children() as $extension) {
			$parts = $extension->children();
			if (bin2hex($extension->child(0, DerElement::TAG_OID)->content()) !== self::OID_CRL_NUMBER) {
				continue;
			}
			$value = array_pop($parts);
			if ($value !== null && $value->tag === DerElement::TAG_OCTET_STRING) {
				return DerElement::at($value->content())->integerHex();
			}
		}

		return null;
	}

	/**
	 * The DER bytes of a PEM or DER list.
	 *
	 * @throws UnexpectedValueException when PEM armour holds no valid base64
	 */
	private static function der(string $text): string {
		if (preg_match('/-----BEGIN X509 CRL-----(.+?)-----END X509 CRL-----/s', $text, $match) !== 1) {
			return $text;
		}

		$der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);
		if ($der === false || $der === '') {
			throw new UnexpectedValueException('The PEM armour holds no valid base64.');
		}

		return $der;
	}

	private static function pem(string $der): string {
		return "-----BEGIN X509 CRL-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END X509 CRL-----\n";
	}

	/**
	 * The PEM certificate blocks in a bundle.
	 *
	 * @return list<string>
	 */
	private static function certificatesIn(string $bundle): array {
		preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $matches);

		return $matches[0];
	}
}
