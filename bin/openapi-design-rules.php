<?php

/**
 * Completes the OpenAPI document that nextcloud/openapi-extractor generates
 * with what the NL API Design Rules ask of it and the extractor cannot write.
 *
 * Run by `composer openapi` right after `generate-spec`, so the committed
 * document stays reproducible from the code:
 *
 * - `servers` carries the versioned OCS base path (`.../api/v1`), and the paths
 *   are relative to it; the deprecated unversioned twins are left out, and
 *   `info.description` says so;
 * - `info.contact` names the maintainer;
 * - every tag an operation uses is defined at the top level;
 * - every 2xx and 3xx response documents the `API-Version` header that
 *   ApiContractMiddleware adds;
 * - schema names are PascalCase (`OCSMeta` becomes `OcsMeta`);
 * - `/openapi.json` documents the endpoint that serves this document, and its
 *   404 as the shared `Problem` schema (`application/problem+json`).
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
$base = '/ocs/v2.php/apps/' . $appId . '/api/v1';

$document->servers = [
	(object)[
		'url' => 'https://{host}' . $base,
		'description' => 'The ' . $document->info->title . ' API, version 1, on a Nextcloud instance.',
		'variables' => (object)[
			'host' => (object)['default' => 'localhost', 'description' => 'The host name of the Nextcloud instance.'],
		],
	],
];
$document->info->description = rtrim((string)($document->info->description ?? '')) . "\n\n"
	. 'Every path is served under `/api/v1/`, the version this document describes. The unversioned `/api/` '
	. 'paths keep working, are deprecated, and name their `/api/v1/` successor in a `Link` header next to a '
	. '`Deprecation` header.';
$document->info->contact = (object)[
	'name' => 'Conduction B.V.',
	'email' => 'info@conduction.nl',
	'url' => 'https://conduction.nl',
];

$paths = new stdClass();
foreach ($document->paths as $route => $item) {
	if (str_starts_with($route, $base . '/') === true) {
		$paths->{substr($route, strlen($base))} = $item;
	}
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
			'404' => (object)[
				'description' => 'The document is not installed.',
				'content' => (object)[
					'application/problem+json' => (object)['schema' => (object)['$ref' => '#/components/schemas/Problem']],
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

// The RFC 9457 problem details shape ApiContractMiddleware gives a plain JSON
// error response on /api/. The OCS routes answer their errors inside the OCS
// envelope (a DataResponse is not decorated), so they keep their own error
// schemas; only plain JSON responses, such as the 404 of /openapi.json,
// reference this one.
$document->components->schemas->Problem = (object)[
	'type' => 'object',
	'description' => 'RFC 9457 problem details. Members of the original error body are kept beside these.',
	'required' => ['type', 'title', 'status'],
	'properties' => (object)[
		'type' => (object)['type' => 'string', 'format' => 'uri-reference', 'example' => 'about:blank'],
		'title' => (object)['type' => 'string', 'example' => 'Not Found'],
		'status' => (object)['type' => 'integer', 'minimum' => 400, 'maximum' => 599, 'example' => 404],
		'detail' => (object)['type' => 'string'],
	],
	'additionalProperties' => true,
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
