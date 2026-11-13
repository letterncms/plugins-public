# Changelog

## Unreleased

## 0.4.0 (2026-11-13)

### Changed

- The Dashboard widget says "No consent issues" instead of showing an empty panel.

## 0.3.0 (2026-11-12)

### Changed

- **Breaking.** Needs the core 0.24 or newer. The banner's **Title**, **Text** and **Privacy Policy** are each one setting with a value per language of the site, shown once per language under its own name, where they were a group of fields per language; a language added to the site gets its words at once. Updating moves the words saved before (`title_de`, `text_en` …) into them (`title: {de: …}`).

### Fixed

- The banner no longer covers the site in the admin's preview, such as the one beside the colours and fonts under **Design**.

## 0.2.0 (2026-11-11)

### Added

- A cookie banner on the published site, in the site's own colours, buttons and switches: **Accept All** and **Only Necessary** weigh the same, and **Choose** opens a choice between analytics and marketing. It appears over the page without moving anything, and works on built pages. On a phone it takes at most half the screen, with buttons as wide as the panel.
- Its title, text and privacy policy are set per language under **Settings → Consent**; an empty field shows what the banner says without it, which fits what is asked about. The rest is said in the page's language.
- Analytics in visitors mode starts counting once a visitor accepts analytics. Other scripts read `window.cmsConsent`, listen for `cms:consent`, or wait as `<script type="text/plain" data-cms-consent="marketing">`.
- Visitors change their mind from the **Cookie Settings** button, or from any link to `#cms-consent`; focus returns to where they came from.
- Visitors who send Global Privacy Control aren't asked, and nothing is accepted for them.
- A site that asks about neither analytics nor marketing shows no banner.
- The **Consent** widget on the home screen stays empty unless Analytics waits for an answer that visitors are never asked for.

### Changed

- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`), which an older CMS does not have.
