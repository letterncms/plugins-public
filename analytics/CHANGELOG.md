# Changelog

## Unreleased

## 0.7.0 (2026-11-11)

### Added

- German, for everything the plugin says in the admin.

### Changed

- The README says what the plugin does, how to install it, and lists its settings, abilities and hooks.
- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`), which an older CMS does not have.
- The empty Analytics screen says what is the case in a title and offers one action, as every empty screen of the admin does.

## 0.6.0 (2026-11-05)

### Changed

- **Breaking.** Needs the CMS 0.9.
- The Editor role a site starts with can view analytics because Analytics gives it **View Site Analytics**, not the CMS: without Analytics, the role doesn’t list it.
- Links to pages in the reports, and which visits count as from the site itself, follow the site’s **Public URL** (Settings → **General**).
- Sync lists page views and visitors as **Analytics**, pulled but not pushed, from this plugin's own `composer.json`.

### Fixed

- Page views per language count a language whose address prefix differs from its code, such as `/pt-br/` for `pt-BR`, and follow the Languages module’s prefixes. Without the module, every view counts for the default language.

## 0.5.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.8, where Site comes with the CMS.

## 0.4.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.7, where Blocks, Email, Collaboration, Languages, MCP, Media and Navigation come with the CMS.

## 0.3.1 (2026-11-04)

### Fixed

- Its words read the same as the rest of the admin in Dutch, French and German: where two packages translated a word differently, which one you saw could change from one page to the next.

## 0.3.0 (2026-11-04)

### Changed

- Needs the CMS 0.5 or later.
- The period and who is on the site now are above the numbers rather than in the top bar, and each list shows its first eight with each one's share of the whole.

## 0.2.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- Analytics' settings are a **Settings** tab on the Analytics screen, beside **Overview**.
- Analytics settings say less and show only while counting is on. **Days to Keep Data** is **Keep Visits For**, and the admin's URL is **Reporting Address**.
- The permission is **View Site Analytics**.
- Analytics is as wide as the other work screens.

## 0.1.0 (2026-11-01)

### Changed

- **Breaking.** Analytics counts page views by default, with no script and nothing read from the browser or kept about visitors. Visitors, sources and devices are now a setting, and that mode waits for a consent signal.
