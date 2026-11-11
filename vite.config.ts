// SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.js')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
