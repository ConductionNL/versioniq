<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Service\Inventory;

/**
 * Writes an inventory as CycloneDX 1.5 or SPDX 2.3 JSON (inventory-export-sbom-metrics
 * D3). A licence that is an SPDX id is passed through, `agpl` from old
 * info.xml files becomes `AGPL-3.0-or-later`, and anything else is kept as a
 * licence name.
 *
 * @psalm-import-type Inventory from InventoryBuilder
 * @psalm-import-type InventoryApp from InventoryBuilder
 *
 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
 */
class SbomWriter {
	public const FORMAT_CYCLONEDX = 'cyclonedx';
	public const FORMAT_SPDX = 'spdx';
	public const FORMATS = [self::FORMAT_CYCLONEDX, self::FORMAT_SPDX];

	/** SPDX licence ids Nextcloud apps declare; passed through as ids. */
	private const SPDX_IDS = [
		'AGPL-3.0-only', 'AGPL-3.0-or-later', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'EUPL-1.2',
		'GPL-2.0-only', 'GPL-2.0-or-later', 'GPL-3.0-only', 'GPL-3.0-or-later', 'LGPL-2.1-or-later',
		'LGPL-3.0-only', 'LGPL-3.0-or-later', 'MIT', 'MPL-2.0',
	];

	/** Old info.xml spellings and the SPDX id each means. */
	private const LEGACY_IDS = ['agpl' => 'AGPL-3.0-or-later'];

	/**
	 * The SBOM in the given format, as a JSON string.
	 *
	 * @param Inventory $inventory  The inventory.
	 * @param string    $format     cyclonedx or spdx.
	 * @param string    $instanceId A stable id of this instance, used in the document name.
	 * @param string    $timestamp  The creation time, ISO 8601.
	 * @param string    $uuid       A random UUID for the serial number or namespace.
	 *
	 * @throws \InvalidArgumentException For an unknown format.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
	 */
	public function write(array $inventory, string $format, string $instanceId, string $timestamp, string $uuid): string {
		$document = match ($format) {
			self::FORMAT_CYCLONEDX => self::cycloneDx(inventory: $inventory, timestamp: $timestamp, uuid: $uuid),
			self::FORMAT_SPDX => self::spdx(inventory: $inventory, instanceId: $instanceId, timestamp: $timestamp, uuid: $uuid),
			default => throw new \InvalidArgumentException('Unknown SBOM format: ' . $format),
		};

		return (string)json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/**
	 * The licence as an SPDX id, or null when it is not one.
	 *
	 * @param string|null $licence The declared licence.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
	 */
	public static function spdxId(?string $licence): ?string {
		if ($licence === null) {
			return null;
		}

		if (in_array($licence, self::SPDX_IDS, true) === true) {
			return $licence;
		}

		return self::LEGACY_IDS[strtolower($licence)] ?? null;
	}

	/**
	 * A CycloneDX 1.5 BOM: the instance as metadata.component, the server and apps as components.
	 *
	 * @param Inventory $inventory The inventory.
	 * @param string    $timestamp The creation time.
	 * @param string    $uuid      The serial number's UUID.
	 *
	 * @return array<string, mixed>
	 */
	private static function cycloneDx(array $inventory, string $timestamp, string $uuid): array {
		$components = [[
			'type' => 'platform',
			'bom-ref' => $inventory['server']['purl'],
			'name' => $inventory['server']['name'],
			'version' => $inventory['server']['version'],
			'purl' => $inventory['server']['purl'],
		]];
		foreach ($inventory['apps'] as $app) {
			$components[] = self::cycloneDxApp(app: $app);
		}

		return [
			'bomFormat' => 'CycloneDX',
			'specVersion' => '1.5',
			'serialNumber' => 'urn:uuid:' . $uuid,
			'version' => 1,
			'metadata' => [
				'timestamp' => $timestamp,
				'tools' => ['components' => [['type' => 'application', 'name' => 'versioniq']]],
				'component' => ['type' => 'platform', 'name' => 'nextcloud', 'version' => $inventory['server']['version']],
			],
			'components' => $components,
		];
	}

	/**
	 * One app as a CycloneDX component.
	 *
	 * @param InventoryApp $app The app.
	 *
	 * @return array<string, mixed>
	 */
	private static function cycloneDxApp(array $app): array {
		$component = [
			'type' => 'application',
			'bom-ref' => $app['purl'],
			'name' => $app['id'],
			'version' => $app['version'],
			'description' => $app['name'],
			'purl' => $app['purl'],
			'properties' => [
				['name' => 'versioniq:shipped', 'value' => $app['shipped'] ? 'true' : 'false'],
				['name' => 'versioniq:source', 'value' => $app['source']],
			],
		];
		if ($app['licence'] !== null) {
			$id = self::spdxId(licence: $app['licence']);
			$component['licenses'] = [['license' => $id !== null ? ['id' => $id] : ['name' => $app['licence']]]];
		}

		if ($app['sha256'] !== null) {
			$component['hashes'] = [['alg' => 'SHA-256', 'content' => $app['sha256']]];
		}

		return $component;
	}

	/**
	 * An SPDX 2.3 document: one package per component, each described by the document.
	 *
	 * @param Inventory $inventory  The inventory.
	 * @param string    $instanceId The instance id.
	 * @param string    $timestamp  The creation time.
	 * @param string    $uuid       The namespace's UUID.
	 *
	 * @return array<string, mixed>
	 */
	private static function spdx(array $inventory, string $instanceId, string $timestamp, string $uuid): array {
		$server = $inventory['server'];
		$packages = [self::spdxPackage(ref: 'SPDXRef-Package-nextcloud-server', name: $server['name'], version: $server['version'], purl: $server['purl'], licence: 'AGPL-3.0-or-later', sha256: null)];
		foreach ($inventory['apps'] as $app) {
			$packages[] = self::spdxPackage(
				ref: 'SPDXRef-Package-' . preg_replace('/[^A-Za-z0-9.\-]/', '-', $app['id']),
				name: $app['id'],
				version: $app['version'],
				purl: $app['purl'],
				licence: $app['licence'],
				sha256: $app['sha256']
			);
		}

		return [
			'spdxVersion' => 'SPDX-2.3',
			'dataLicense' => 'CC0-1.0',
			'SPDXID' => 'SPDXRef-DOCUMENT',
			'name' => 'nextcloud-' . $instanceId,
			'documentNamespace' => 'https://spdx.org/spdxdocs/versioniq-' . $instanceId . '-' . $uuid,
			'creationInfo' => ['created' => $timestamp, 'creators' => ['Tool: versioniq']],
			'packages' => $packages,
			'relationships' => array_map(
				static fn (array $package): array => [
					'spdxElementId' => 'SPDXRef-DOCUMENT',
					'relationshipType' => 'DESCRIBES',
					'relatedSpdxElement' => $package['SPDXID'],
				],
				$packages
			),
		];
	}

	/**
	 * One SPDX package.
	 *
	 * @param string      $ref     Its SPDXID.
	 * @param string      $name    Its name.
	 * @param string      $version Its version.
	 * @param string      $purl    Its package URL.
	 * @param string|null $licence The declared licence.
	 * @param string|null $sha256  The recorded SHA-256.
	 *
	 * @return array<string, mixed>
	 */
	private static function spdxPackage(string $ref, string $name, string $version, string $purl, ?string $licence, ?string $sha256): array {
		$id = self::spdxId(licence: $licence);
		$package = [
			'SPDXID' => $ref,
			'name' => $name,
			'versionInfo' => $version,
			'downloadLocation' => 'NOASSERTION',
			'licenseConcluded' => 'NOASSERTION',
			'licenseDeclared' => ($id ?? 'NOASSERTION'),
			'copyrightText' => 'NOASSERTION',
			'externalRefs' => [['referenceCategory' => 'PACKAGE-MANAGER', 'referenceType' => 'purl', 'referenceLocator' => $purl]],
		];
		if ($id === null && $licence !== null) {
			// Not an SPDX id: an undefined LicenseRef would make the document invalid, so the
			// declared text is kept as a comment instead.
			$package['licenseComments'] = 'Declared licence: ' . $licence;
		}

		if ($sha256 !== null) {
			$package['checksums'] = [['algorithm' => 'SHA256', 'checksumValue' => $sha256]];
		}

		return $package;
	}
}
