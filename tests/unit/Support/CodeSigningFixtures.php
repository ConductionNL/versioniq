<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Tests\Unit\Support;

use OCA\Versioniq\Service\Safety\DerElement;
use OpenSSLAsymmetricKey;

/**
 * Real code-signing material, made with OpenSSL while the test runs: a
 * certificate authority, certificates it issues, and revocation lists it
 * signs (with a chosen CRL number and revoked serials). Nothing is
 * committed, so nothing expires.
 */
final class CodeSigningFixtures {
	private const OID_ECDSA_SHA256 = '2a8648ce3d040302';
	private const OID_RSA_SHA256 = '2a864886f70d01010b';

	/**
	 * A self-signed certificate authority.
	 *
	 * @return array{key: OpenSSLAsymmetricKey, cert: string}
	 */
	public static function authority(string $commonName = 'Test Code Signing Authority', bool $rsa = false): array {
		$key = openssl_pkey_new($rsa
			? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
			: ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		$csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
		$cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], 1);
		openssl_x509_export($cert, $pem);

		return ['key' => $key, 'cert' => $pem];
	}

	/**
	 * A certificate for an app, issued by $authority with serial $serial.
	 *
	 * @param array{key: OpenSSLAsymmetricKey, cert: string} $authority
	 */
	public static function appCertificate(array $authority, string $appId, int $serial): string {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		$csr = openssl_csr_new(['commonName' => $appId], $key, ['digest_alg' => 'sha256']);
		$cert = openssl_csr_sign($csr, $authority['cert'], $authority['key'], 3650, ['digest_alg' => 'sha256'], $serial);
		openssl_x509_export($cert, $pem);

		return $pem;
	}

	/**
	 * A PEM revocation list signed by $authority.
	 *
	 * @param array{key: OpenSSLAsymmetricKey, cert: string} $authority
	 * @param ?int $number The CRL number, null for none.
	 * @param list<int> $revoked The revoked serial numbers.
	 */
	public static function revocationList(array $authority, ?int $number, array $revoked = []): string {
		$rsa = openssl_pkey_get_details($authority['key'])['type'] === OPENSSL_KEYTYPE_RSA;
		$algorithm = self::tlv(0x30, self::tlv(0x06, (string)hex2bin($rsa ? self::OID_RSA_SHA256 : self::OID_ECDSA_SHA256)) . ($rsa ? self::tlv(0x05, '') : ''));
		$now = self::tlv(0x17, gmdate('ymdHis') . 'Z');

		$signed = self::tlv(0x02, "\x01") . $algorithm . self::subjectOf($authority['cert']) . $now;
		if ($revoked !== []) {
			$signed .= self::tlv(0x30, implode('', array_map(static fn (int $serial): string => self::tlv(0x30, self::integer($serial) . $now), $revoked)));
		}
		if ($number !== null) {
			$signed .= self::tlv(0xA0, self::tlv(0x30, self::tlv(0x30, self::tlv(0x06, "\x55\x1d\x14") . self::tlv(0x04, self::integer($number)))));
		}
		$signed = self::tlv(0x30, $signed);
		openssl_sign($signed, $signature, $authority['key'], OPENSSL_ALGO_SHA256);
		$der = self::tlv(0x30, $signed . $algorithm . self::tlv(0x03, "\x00" . $signature));

		return "-----BEGIN X509 CRL-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END X509 CRL-----\n";
	}

	/**
	 * The DER subject Name of a PEM certificate, used as a list's issuer.
	 */
	private static function subjectOf(string $pem): string {
		$der = (string)base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $pem));
		$fields = DerElement::at($der)->children()[0]->children();

		return $fields[$fields[0]->tag === 0xA0 ? 5 : 4]->encoded();
	}

	private static function integer(int $value): string {
		$hex = dechex($value);
		$bytes = (string)hex2bin(strlen($hex) % 2 === 1 ? '0' . $hex : $hex);

		return self::tlv(0x02, ord($bytes[0]) > 0x7F ? "\x00" . $bytes : $bytes);
	}

	private static function tlv(int $tag, string $content): string {
		$length = strlen($content);
		if ($length < 0x80) {
			return chr($tag) . chr($length) . $content;
		}
		$lengthBytes = ltrim(pack('N', $length), "\x00");

		return chr($tag) . chr(0x80 | strlen($lengthBytes)) . $lengthBytes . $content;
	}
}
