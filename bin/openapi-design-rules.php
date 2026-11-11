<?php

/**
 * Completes the OpenAPI document that nextcloud/openapi-extractor generates
 * with what the NL API Design Rules ask of it and the extractor cannot write.
 *
 * Run by `composer openapi` right after `generate-spec`, so the committed
 * document stays reproducible from the code:
 *
 * - `servers` carries the OCS base path, and the paths are relative to it;
 * - `info.contact` names the maintainer;
 * - every tag an operation uses is defined at the top level;
 * - every 2xx and 3xx response documents the `API-Version` header that
 *   ApiContractMiddleware adds;
 * - schema names are PascalCase (`OCSMeta` becomes `OcsMeta`);
 * - `/openapi.json` documents the endpoint that serves this document.
 *
 * Usage: php bin/openapi-design-rules.php openapi.json
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

$path = ($argv[1] ?? 'openapi.json');
$document = json_decode((string)file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);

$appId = 'versioniq';
$base = '/ocs/v2.php/apps/' . $appId . '/api';

$document->servers = [
	(object)[
		'url' => 'https://{host}' . $base,
		'description' => 'The ' . $document->info->title . ' API on a Nextcloud instance.',
		'variables' => (object)[
			'host' => (object)['default' => 'localhost', 'description' => 'The host name of the Nextcloud instance.'],
		],
	],
];
$document->info->contact = (object)[
	'name' => 'Conduction B.V.',
	'email' => 'info@conduction.nl',
	'url' => 'https://conduction.nl',
];

$paths = new stdClass();
foreach ($document->paths as $route => $item) {
	$relative = str_starts_with($route, $base . '/') ? substr($route, strlen($base)) : $route;
	$paths->{$relative} = $item;
}

$paths->{'/openapi.json'} = (object)[
	'get' => (object)[
		'operationId' => 'open_api-show',
		'summary' => 'The OpenAPI document of this API',
		'description' => 'Returns this document. It needs no authentication and can be read from any origin.',
		'tags' => ['open_api'],
		'security' => [],
		'responses' => (object)[
			'200' => (object)[
				'description' => 'The OpenAPI document.',
				'headers' => (object)[
					'access-control-allow-origin' => (object)[
						'description' => 'Always `*`.',
						'schema' => (object)['type' => 'string', 'enum' => ['*']],
					],
				],
				'content' => (object)[
					'application/json' => (object)['schema' => (object)['type' => 'object', 'additionalProperties' => true]],
				],
			],
		],
	],
];
$document->paths = $paths;

$document->components->headers = (object)[
	'API-Version' => (object)[
		'description' => 'The version of the API that answered, which is the installed app version.',
		'schema' => (object)['type' => 'string', 'example' => '1.0.0'],
	],
];

$tags = [];
foreach ($document->paths as $item) {
	foreach ($item as $verb => $operation) {
		if (is_object($operation) === false || isset($operation->responses) === false) {
			continue;
		}

		foreach (($operation->tags ?? []) as $tag) {
			$tags[$tag] = true;
		}

		foreach ($operation->responses as $status => $response) {
			if (preg_match('/^[23]\d\d$/', (string)$status) === 1) {
				$response->headers = ($response->headers ?? new stdClass());
				$response->headers->{'API-Version'} = (object)['$ref' => '#/components/headers/API-Version'];
			}
		}
	}
}

$descriptions = [
	'api' => 'Installed apps, their versions, pins, advisories, updates and the audit log',
	'forge' => 'The self-hosted Forgejo or Gitea host that app sources are read from',
	'open_api' => 'The OpenAPI document that describes this API',
	'settings' => 'Instance settings of Versioniq',
];
ksort($tags);
$document->tags = [];
foreach (array_keys($tags) as $tag) {
	$document->tags[] = (object)['name' => $tag, 'description' => ($descriptions[$tag] ?? 'Operations tagged ' . $tag)];
}

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$json = str_replace(['"OCSMeta"', '#/components/schemas/OCSMeta"'], ['"OcsMeta"', '#/components/schemas/OcsMeta"'], $json);
file_put_contents($path, $json . "\n");
