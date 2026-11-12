# Changelog

## Unreleased

## 0.10.0 (2026-11-12)

### Added

- A term's archive in a collection (`/blog/topics/tides`, with the core that has them) is in the sitemap of its language, with links to the same term's archives in the other languages, and has a Markdown version, `/blog/topics/tides.md`, that lists the entries filed under the term. A term kept out of search keeps its archives out too.
- The Audit Log names what SEO changed in the past tense: **Created Redirect**, **Saved Redirects**.

### Changed

- Needs CMS 0.26: a redirect's row button says **Delete** through the core's `removeLabel`, which an older CMS does not have.
- A redirect's status says what it is first: **Permanent (301)**, **Temporary (302)**, and a 307 or 308 made elsewhere reads **Temporary (307)** or **Permanent (308)** instead of a bare number. A row's button says **Delete**.
- `seo.sitemap` is told which language's sitemap is being written (`$locale`, `null` on a site with one), and an address that names its `locale` goes in that language's sitemap. One that names none stays in the default language's, as before. One that names an `entry` is left out when that entry is marked noindex, and gets the entry's other languages as alternates.
- In the SEO panel, **Page Type** shows what applies while nothing is chosen (**Web Page**, or **Article** for a post) as its value, not as a faint hint. **Score** and **What AI Crawlers Read** are two rows of one shape.
- With no redirects yet, **New Redirect** is offered where the table would be; otherwise it is in the bar, the one place a redirect is made: the table has no add button of its own. On a phone each redirect is a card of its fields.
- **Breaking.** Needs the core 0.24 or newer, which writes `llms.txt` for every site. SEO no longer writes its own: it makes the site's richer through the `site.llms` filter, with the **Summary for AI**, each page's description, a link to its Markdown twin, and only the pages search engines may list.

### Fixed

- In the SEO panel, a long description shown as the fallback in **Meta Description** is no longer cut off in the middle of a line; the search preview below shows it whole.
- The search preview's title is the admin's own blue, readable in the dark too.

## 0.9.0 (2026-11-11)

### Added

- `redirects/create` adds one redirect without replacing the list, for the admin, agents, the API and the CLI.
- Every SEO ability says what it answers with (`output`), for agents, the API and the CLI.

### Changed

- **New Redirect** is in the top bar of the Redirects tab and asks for the redirect in a dialog. With none yet the tab says **No redirects yet**; the table shows once there is one, and its own button adds a row (**Add Redirect**).
- **Breaking.** `redirects/save` is `redirects/set`: it puts the whole list in place, as `set` does everywhere in the core. The old name is gone.
- The SEO settings and the SEO panel say less: the notes under the fields are gone but one, that blocked search crawlers can’t cite you; placeholders show the shape instead. **Who You Are** is **Organization**.

## 0.8.0 (2026-11-11)

### Added

- German, for everything the plugin says in the admin.

### Changed

- The Editor role a site starts with can manage SEO settings and redirects: **Manage SEO Settings and Redirects** comes with it, as the permissions of the other plugins do.
- The README says what the plugin does, how to install it, and lists its settings, abilities and hooks.
- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`), which an older CMS does not have.

## 0.7.0 (2026-11-05)

### Changed

- **Breaking.** Needs the CMS 0.9.
- **Breaking.** `Cms\Seo\Meta::siteUrl()` is gone: use `cms()->url()`, which canonical links, the sitemap, feeds, structured data and the Markdown versions now build on.
- **Breaking.** Needs the Site module: SEO turns it on with itself.
- Changing an SEO setting rebuilds the site through the build’s signature, like a plugin update, instead of queueing a full rebuild by hand.

### Fixed

- A preview has one robots tag, the core’s `noindex, nofollow`, instead of two.

## 0.6.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.8, where Site comes with the CMS.

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
