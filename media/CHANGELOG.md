# Changelog

## Unreleased

### Changed

- Needs the CMS 0.5 or later.
- The library's search, kind and folder are in the address, so a link opens it filtered.

## 0.3.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- Media's settings are a **Settings** tab on the Media screen, beside **Library**.
- The library no longer has a Published column beside Uploaded: a file is uploaded, not published. A search or filter that finds nothing says “No matches”, with **Clear Filters**. On a phone, the search box takes the full width.
- Uploads show their progress in a notice at the bottom of the window, wherever you are in the library, then how many files arrived and which couldn't. The empty library no longer repeats the top bar's **Upload**.
- In Settings → Media, **Max Upload Size** is in MB like the server's limit under it, and **Image Quality** is a slider from 40 to 95.
- Deleting one file names it, and small files show their size as “109B”.
- Permissions are in title case, such as **View the Media Library**.
- Media's switches sit together in one group, and **Remove Photo Metadata** says what it removes in a note.
- An empty library is its message alone: no filters, display options, views or a count of 0 until the first file is in.

### Fixed

- A file's URL that can't be copied says “Couldn’t copy the URL” instead of the browser's own error.

## 0.2.0 (2026-11-02)

### Added

- `@` in the assistant's chat finds files from the library.

## 0.1.0 (2026-11-01)

### Added

- Media records are kept in `content/media`, so a fresh install or deploy from git has every image. `php cms media:check` and `php cms build --strict` report what points at nothing, and deleting a file still in use is refused.
