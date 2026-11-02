// SPDX-License-Identifier: EUPL-1.2

/**
 * The message of a caught Error, or the fallback for anything else that was thrown.
 *
 * @param caught what the catch block received
 * @param fallback the message to show when it is not an Error
 */
export function errorMessage (caught: unknown, fallback: string): string {
	return caught instanceof Error ? caught.message : fallback
}
