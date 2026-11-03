<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Versioniq\Http;

use OCP\IRequest;

/**
 * The text fields a controller method reads from the request, declared as the
 * public properties of a subclass: each property is one request parameter,
 * its default the value used when the parameter is absent.
 *
 * fromRequest() reads them as Nextcloud's Dispatcher reads declared method
 * parameters (getParam() with the property default), and returns null when a
 * supplied value is not text, where a declared string parameter would make
 * the Dispatcher answer 400. The method names its fields class with
 * #[RequestFieldsFrom], which is how the OpenAPI document still lists them.
 *
 * @psalm-consistent-constructor
 */
abstract class RequestFields {
	/**
	 * @spec openspec/specs/version-management/spec.md
	 */
	public static function fromRequest(IRequest $request): ?static {
		$fields = new static();
		foreach (array_keys(get_object_vars($fields)) as $name) {
			/** @var mixed $value */
			$value = $request->getParam($name, $fields->$name);
			if ($value !== null && !is_string($value)) {
				return null;
			}
			$fields->$name = $value;
		}

		return $fields;
	}
}
