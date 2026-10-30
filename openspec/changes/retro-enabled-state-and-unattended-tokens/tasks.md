# retro-enabled-state-and-unattended-tokens tasks

## 1. Record

- [x] 1.1 Read the enabled-state carry in both installers and the finalizer, and the no-uid branch of `PatResolver::candidatesFor()`, against `development`.
- [x] 1.2 Record `built.requirement` in `capabilities.json` for `ins-keep-enabled-state`, `aut-unattended-private` and the ten rows whose requirement was picked by hand.

## 2. Tests

- [ ] 2.1 Add a unit test that installs over a disabled app and over an app enabled for one group, and asserts `InstallFinalizer` writes the previous `enabled` value. Verify: `tests/unit/Service/Installer/InstallFinalizerTest.php`.
- [ ] 2.2 Assert in `tests/unit/Service/Pat/PatResolverTest.php` that `findFor()` with a null uid skips an unshared token that matches better than a shared one.

## 3. Close

- [ ] 3.1 Archive this change.
