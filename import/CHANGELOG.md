# Changelog

## Unreleased

## 0.2.0 (2026-11-11)

### Added

- **Import** brings a site over from a WordPress export (posts, pages, the media they use, and redirects from their old addresses when SEO is on), a CSV file, or a folder of Markdown with front matter. Put the file in `storage/app/import/`, look at what it would do with `php cms import wordpress export.xml --dry-run` or the `import/preview` ability, then run it. Running it again updates what changed and never makes a second copy.
- A file the old site did not hand over is reported; running the import again fetches it and puts it into the entries that show it.
- The **Import** widget, for those who add it to their home screen, counts what has been brought over and the sources waiting in the import folder. With neither, it shows nothing.
- `import/sources`, `import/preview` and `import/run` say what they answer with, so an agent or an API client knows the report before it asks for one.
- `import/run` takes `dryRun: true`: it refuses what the import would refuse and lists every entry, file and redirect it would make, without making them.

### Changed

- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`, `entryService()`), which an older CMS does not have.
