// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// This app declared `@nextcloud/stylelint-config` as a devDependency and then
// never extended it: there was no stylelint config file of any kind, and no
// `stylelint` key in package.json. stylelint 16 tolerated that quietly; 17
// does not, and fails the run outright:
//
//   ConfigurationError: No rules found within configuration.
//   Have you provided a "rules" property?
//
// A declared linter with no rules is not a lenient linter, it is an absent
// one — the package was installed on every CI run and judged nothing. This
// points it at the config it already depends on, which is what the rest of
// the fleet extends.
//
// This package is `"type": "module"`, so a `.js` config file IS an ES module
// and `module.exports` throws `ReferenceError: module is not defined in ES
// module scope`. Export syntax, not CommonJS.
export default {
	extends: '@nextcloud/stylelint-config',
}
