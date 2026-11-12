# write-guards-and-advisory-alerts tasks

## 1. Record

- [x] 1.1 Read the three behaviours against `development`: the password attribute on every write route in `lib/Controller/`, `InstallerService::isManageableApp()` and its three callers, and `AdvisoryNotifier::notifyNewAdvisories()` on each sweep of `AdvisoryRefreshJob`.
- [x] 1.2 Point `adm-password-confirm`, `saf-protect-core` and `adv-notify-urgent` in `capabilities.json` at these requirements.

## 2. Tests

- [ ] 2.1 Add a unit test that reflects over every write route of `ApiController`, `ForgeController` and `SettingsController` and fails when one lacks `#[PasswordConfirmationRequired]`. Verify: `tests/unit/Controller/PasswordConfirmationTest.php`.

## 3. Close

- [ ] 3.1 Archive this change.
