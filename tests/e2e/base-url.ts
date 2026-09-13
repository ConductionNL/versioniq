/*
 * SPDX-FileCopyrightText: 2026 Versioniq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one place this suite decides which Nextcloud it talks to.
 *
 * versioniq aims at a disposable rig on port 8099 by default, which is not an
 * instance anybody shares, so the guard below is a no-op for the default as it
 * stands today. It is here because a default is a thing that gets edited: the
 * moment someone points this suite at port 8080 to save spinning a rig up, it
 * is writing into the shared development stack, which bind-mounts the host
 * checkouts under `apps-extra/` and holds data colleagues are working on.
 * These specs pin, unpin and change app config. A future default cannot become
 * 8080 quietly while this module is the only entrance.
 *
 * The default is unchanged by this module: an unset environment still resolves
 * `http://localhost:8099`, exactly as `playwright.config.ts` did before.
 *
 * All four fleet names are accepted. The shared quality workflow exports the
 * target as `BASE_URL`, `NEXTCLOUD_URL` and `NC_BASE_URL`, not as
 * `PLAYWRIGHT_BASE_URL`; a resolver that accepts only the last one hard-fails
 * every CI run, which is what happened to openconnector.
 */

import { assertInstancePermitted } from "./shared-instance.ts";

/** Environment variable names accepted as the target, in priority order. */
const CANDIDATES = [
	"PLAYWRIGHT_BASE_URL",
	"NC_BASE_URL",
	"NEXTCLOUD_URL",
	"BASE_URL",
] as const;

/** The rig this suite aims at when nothing in the environment names one. */
const DEFAULT_BASE_URL = "http://localhost:8099";

/**
 * Resolve the base URL of the Nextcloud under test.
 *
 * @throws When the target is the shared development instance and no opt-in
 *         flag names it. See tests/e2e/shared-instance.ts.
 * @return The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	for (const name of CANDIDATES) {
		const value = process.env[name];
		if (value && value.trim() !== "") {
			return assertInstancePermitted(value.trim().replace(/\/+$/, ""));
		}
	}

	return assertInstancePermitted(DEFAULT_BASE_URL);
}

/** The resolved base URL, evaluated once per process. */
export const BASE_URL = resolveBaseUrl();
