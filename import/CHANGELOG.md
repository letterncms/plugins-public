# Changelog

## Unreleased

## 0.5.0 (2026-11-13)

### Changed

- The Dashboard widget says "Nothing imported yet" instead of showing an empty panel.

## 0.4.0 (2026-11-12)

### Changed

- Text goes into the field marked as the collection's body (**Use as Body**). Needs the CMS 0.28.

## 0.3.0 (2026-11-12)

### Added

- The Audit Log names an import in the past tense: **Ran Import**.
- **Import** has a screen of its own, under **Settings → Import**. Upload a WordPress export, a CSV file or a zip of Markdown files, and see what importing it would do before anything is written: what would be created, updated or left unchanged, which entries it would replace, the files it would fetch and the redirects it would add.
- **Options** says where the entries go and, for a CSV, what each column is.
- **Start Import** shows how far the import is and can be canceled. It runs a few seconds at a time, so reloading or closing the page loses nothing.
- **History** keeps the report of every import, with the way to each entry it made.
- Agents and the API do the same with `import-sources/get`, `update`, `preview` and `delete`, and `import-runs/start`, `step`, `cancel`, `list`, `get` and `delete`.

### Changed

- Needs CMS 0.26: an empty **Files** or **History** list offers **Upload File**, an empty state's action an older CMS does not draw.
- An empty **Files** or **History** list offers **Upload File**.
- `import/sources` and an upload give each source an `id`, and a report says whether it is `complete`, how many `records` the source has, and which entries are a `collision` with one already there.

## 0.2.0 (2026-11-11)

### Added

- **Import** brings a site over from a WordPress export (posts, pages, the media they use, and redirects from their old addresses when SEO is on), a CSV file, or a folder of Markdown with front matter. Put the file in `storage/app/import/`, look at what it would do with `php cms import wordpress export.xml --dry-run` or the `import/preview` ability, then run it. Running it again updates what changed and never makes a second copy.
- A file the old site did not hand over is reported; running the import again fetches it and puts it into the entries that show it.
- The **Import** widget, for those who add it to their home screen, counts what has been brought over and the sources waiting in the import folder. With neither, it shows nothing.
- `import/sources`, `import/preview` and `import/run` say what they answer with, so an agent or an API client knows the report before it asks for one.
- `import/run` takes `dryRun: true`: it refuses what the import would refuse and lists every entry, file and redirect it would make, without making them.

### Changed

- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`, `entryService()`), which an older CMS does not have.
