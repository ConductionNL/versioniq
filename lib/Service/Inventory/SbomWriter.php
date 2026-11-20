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
 * @psalm-type SbomStamp = array{instanceId:string,timestamp:string,uuid:string}
 * @psalm-type SpdxComponent = array{name:string,version:string,purl:string,licence:?string,sha256:?string}
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
	 * @param Inventory $inventory The inventory.
	 * @param string    $format    cyclonedx or spdx.
	 * @param SbomStamp $stamp     A stable id of this instance (used in the document name), the
	 *                             creation time (ISO 8601) and a random UUID for the serial number
	 *                             or namespace.
	 *
	 * @throws \InvalidArgumentException For an unknown format.
	 *
	 * @spec openspec/changes/inventory-export-sbom-metrics/specs/inventory-export/spec.md
	 */
	public function write(array $inventory, string $format, array $stamp): string {
		$document = match ($format) {
			self::FORMAT_CYCLONEDX => self::cycloneDx(inventory: $inventory, stamp: $stamp),
			self::FORMAT_SPDX => self::spdx(inventory: $inventory, stamp: $stamp),
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
	 * @param SbomStamp $stamp     The creation time and the serial number's UUID.
	 *
	 * @return array<string, mixed>
	 */
	private static function cycloneDx(array $inventory, array $stamp): array {
		return [
			'bomFormat' => 'CycloneDX',
			'specVersion' => '1.5',
			'serialNumber' => 'urn:uuid:' . $stamp['uuid'],
			'version' => 1,
			'metadata' => [
				'timestamp' => $stamp['timestamp'],
				'tools' => ['components' => [['type' => 'application', 'name' => 'versioniq']]],
				'component' => ['type' => 'platform', 'name' => 'nextcloud', 'version' => $inventory['server']['version']],
			],
			'components' => self::cycloneDxComponents(inventory: $inventory),
		];
	}

	/**
	 * The server as a platform component, then every app.
	 *
	 * @param Inventory $inventory The inventory.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function cycloneDxComponents(array $inventory): array {
		$server = $inventory['server'];
		$components = [[
			'type' => 'platform',
			'bom-ref' => $server['purl'],
			'name' => $server['name'],
			'version' => $server['version'],
			'purl' => $server['purl'],
		]];
		foreach ($inventory['apps'] as $app) {
			$components[] = self::cycloneDxApp(app: $app);
		}

		return $components;
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

		return self::withCycloneDxEvidence(component: $component, app: $app);
	}

	/**
	 * The component with the app's licence and hash, where it has them.
	 *
	 * @param array<string, mixed> $component The component.
	 * @param InventoryApp         $app       The app.
	 *
	 * @return array<string, mixed>
	 */
	private static function withCycloneDxEvidence(array $component, array $app): array {
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
	 * @param Inventory $inventory The inventory.
	 * @param SbomStamp $stamp     The instance id, the creation time and the namespace's UUID.
	 *
	 * @return array<string, mixed>
	 */
	private static function spdx(array $inventory, array $stamp): array {
		$packages = self::spdxPackages(inventory: $inventory);

		return [
			'spdxVersion' => 'SPDX-2.3',
			'dataLicense' => 'CC0-1.0',
			'SPDXID' => 'SPDXRef-DOCUMENT',
			'name' => 'nextcloud-' . $stamp['instanceId'],
			'documentNamespace' => 'https://spdx.org/spdxdocs/versioniq-' . $stamp['instanceId'] . '-' . $stamp['uuid'],
			'creationInfo' => ['created' => $stamp['timestamp'], 'creators' => ['Tool: versioniq']],
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
	 * The server package, then one package per app.
	 *
	 * @param Inventory $inventory The inventory.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function spdxPackages(array $inventory): array {
		$server = $inventory['server'] + ['licence' => 'AGPL-3.0-or-later', 'sha256' => null];
		$packages = [self::spdxPackage(ref: 'SPDXRef-Package-nextcloud-server', component: $server)];
		foreach ($inventory['apps'] as $app) {
			$packages[] = self::spdxPackage(
				ref: 'SPDXRef-Package-' . preg_replace('/[^A-Za-z0-9.\-]/', '-', $app['id']),
				component: ['name' => $app['id']] + $app
			);
		}

		return $packages;
	}

	/**
	 * One SPDX package.
	 *
	 * @param string        $ref       Its SPDXID.
	 * @param SpdxComponent $component Its name, version, package URL, declared licence and recorded SHA-256.
	 *
	 * @return array<string, mixed>
	 */
	private static function spdxPackage(string $ref, array $component): array {
		$id = self::spdxId(licence: $component['licence']);
		$package = [
			'SPDXID' => $ref,
			'name' => $component['name'],
			'versionInfo' => $component['version'],
			'downloadLocation' => 'NOASSERTION',
			'licenseConcluded' => 'NOASSERTION',
			'licenseDeclared' => ($id ?? 'NOASSERTION'),
			'copyrightText' => 'NOASSERTION',
			'externalRefs' => [['referenceCategory' => 'PACKAGE-MANAGER', 'referenceType' => 'purl', 'referenceLocator' => $component['purl']]],
		];

		return self::withSpdxEvidence(package: $package, component: $component, spdxId: $id);
	}

	/**
	 * The package with the licence comment and checksum, where they apply.
	 *
	 * @param array<string, mixed> $package   The package.
	 * @param SpdxComponent        $component The component.
	 * @param string|null          $spdxId    The licence as an SPDX id, or null.
	 *
	 * @return array<string, mixed>
	 */
	private static function withSpdxEvidence(array $package, array $component, ?string $spdxId): array {
		if ($spdxId === null && $component['licence'] !== null) {
			// Not an SPDX id: an undefined LicenseRef would make the document invalid, so the
			// declared text is kept as a comment instead.
			$package['licenseComments'] = 'Declared licence: ' . $component['licence'];
		}

		if ($component['sha256'] !== null) {
			$package['checksums'] = [['algorithm' => 'SHA256', 'checksumValue' => $component['sha256']]];
		}

		return $package;
	}
}
