# Changelog

## Unreleased

## 0.5.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.7, where Blocks, Email, Collaboration, Languages, MCP, Media and Navigation come with the CMS.

## 0.4.0 (2026-11-04)

### Changed

- Needs the CMS 0.5 or later.
- **Redirects** is one table you edit in place, with **New Redirect** above it; rows left empty are dropped when you save.

## 0.3.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- SEO is listed under Plugins in Settings, and **Redirects** are saved from the save bar like the settings beside them.
- SEO has one place in Settings: Redirects are a tab of the SEO settings page, saved from the same save bar, instead of a separate item. Links to the old Redirects screen open the tab. The plugin and its settings page are both called SEO.
- **Page Type** shows what it falls back to while empty, **Article** for posts and **Web Page** otherwise, and its choices are in title case.
- The SEO tab starts with its fields, each with its label above it and as wide as the panel (a switch on its own row), then the search preview and a one-line score that opens to the checks still to improve. What a field falls back to shows as its placeholder (the page title, the first paragraph) instead of a note under it. Checks are named in title case.
- The notes under the SEO settings are a few words each.
- **New Redirect** is the Redirects tab's one button, in the top bar.
- SEO settings read plainly: **Type** (Organization, Local Business or Person) under **Who You Are**, **AI Crawlers**, **Publish Markdown Versions of Pages** and **Discourage All Indexing**, with a short note where one helps. **Profiles** asks for addresses, and **SEO Title** defaults to the page title.

### Fixed

- The SEO settings' section headings, **Who You Are**, **AI Crawlers** and **Indexing and Security**, are in Dutch and French too.

## 0.2.0 (2026-11-02)

### Changed

- Redirects no longer check the core's `CMS_LOCKDOWN`, which is gone. This version works with every 0.x of the core; the previous one stops saving on a core without lockdown.

## 0.1.0 (2026-11-01)

### Added

- Set a security contact in SEO settings and the site publishes `/.well-known/security.txt`, renewed on every build. A home page's social title now matches its page title, and a site with no favicon no longer gets a 404 for `/favicon.ico`.
