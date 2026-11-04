# Changelog

## Unreleased

## 0.3.1 (2026-11-04)

### Fixed

- Its words read the same as the rest of the admin in Dutch, French and German: where two packages translated a word differently, which one you saw could change from one page to the next.

## 0.3.0 (2026-11-04)

### Changed

- Needs the CMS 0.5 or later.
- **Make Default**, **Remove** and the URL prefix are in a language's row menu too, besides the dialog its row opens. **Remove** shows while the language still has entries, and the dialog says why it can't be used yet.

## 0.2.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- **Languages** is listed under Plugins in Settings.
- **Add Language** is a button at the top of Languages that opens a list you can search, each language named once. Any language code works too.
- A language's row opens it, with what can be done: **Make Default** and **Remove**, or for the default language **Add URL Prefix** or **Remove URL Prefix**, instead of a switch under the list.
- The list of languages fits its page: name, code, URL prefix and entries, each named on a phone.

## 0.1.0 (2026-11-01)

### Added

- A site reads fully in each language. Links follow the visitor's language, collections can have a URL pattern per language, menu items and forms have their wording per language, a missing page and the text from the code answer in the page's language, and each language gets its own sitemap. `hreflang` leaves out pages that aren't indexed, and a translation no longer inherits a canonical or noindex.
