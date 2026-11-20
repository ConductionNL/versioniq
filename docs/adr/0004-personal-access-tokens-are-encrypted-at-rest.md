# 4. Access tokens are encrypted at rest and decrypted only inside a callback

- Status: Accepted
- Date: 2026-05-02

## Context

Installing from private repositories, and raising API rate limits, needs personal access tokens for the forge. These tokens grant access outside Nextcloud, so storing or passing them around in plain text is a risk.

## Decision

- Store tokens in the app's own database table, encrypted with `OCP\Security\ICrypto::encrypt()` as soon as they are received. Only the ciphertext and a short display hint are persisted; the token is never returned by the API.
- Decrypt only inside `PatManager::useToken()`, which passes the plaintext to a callback and discards it afterwards. No decrypted value is kept on a property or returned across method boundaries.
- Validate a token when it is uploaded, and keep tokens per admin with an optional share to other admins.

## Consequences

- A database dump does not expose usable tokens without the instance secret.
- Code that needs a token has to be written as a callback to `useToken()`.
- Token values never reach installer error paths, which keeps them out of audit messages.

## Source

`openspec/changes/archive/2026-05-02-add-github-pat-management/design.md` ("Storage", "Encryption", "Why per-admin default with optional share"); implemented in `lib/Service/Pat/PatManager.php`.
