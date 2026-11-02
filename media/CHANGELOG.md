# Changelog

## Unreleased

### Added

- `@` in the assistant's chat finds files from the library.

## 0.1.0 (2026-11-01)

### Added

- Media records are kept in `content/media`, so a fresh install or deploy from git has every image. `php cms media:check` and `php cms build --strict` report what points at nothing, and deleting a file still in use is refused.
