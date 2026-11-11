# Changelog

## Unreleased

## 0.5.0 (2026-11-11)

### Added

- **Newsletter lists.** The **Newsletter Signup** block adds visitors to one of your Brevo lists: an email field and a button that look like every other form on the site and work without a script. Choose the default list under Settings → Brevo, or a list per block.
- **Double opt-in**, on by default: the site mails a link that is good for two days. It opens a page in the site's design whose button confirms, so a mail scanner that follows links subscribes nobody, and only a confirmed address reaches Brevo. The email is written in the language of the page, or says what you set. One address is mailed at most three times an hour.
- Lists work with the key under Settings → Email. Settings → Brevo asks for a key only while there is none there.
- A site hosted elsewhere sends signups to the **Submission Address** set under Forms.
- The abilities `brevo/lists` and `brevo/subscribe`, the `brevo.subscribed` action and the `cms://brevo` context for agents.
- German, for everything the plugin says.

### Changed

- **Breaking.** Needs CMS 0.22: the plugin calls the core by its new names (`setting()`), which an older CMS does not have.

### Fixed

- The README no longer says to buy the plugin: it is free, under the MIT license.

## 0.4.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.8, where Site comes with the CMS.

## 0.3.0 (2026-11-04)

### Changed

- **Breaking.** Needs the CMS 0.7, where Blocks, Email, Collaboration, Languages, MCP, Media and Navigation come with the CMS.

## 0.2.1 (2026-11-04)

### Fixed

- Its words read the same as the rest of the admin in Dutch, French and German: where two packages translated a word differently, which one you saw could change from one page to the next.

## 0.2.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- The note under **Brevo API Key** says only where to find it.

## 0.1.0 (2026-11-01)

### Changed

- Brevo is now a free plugin from the store, and no longer comes in the download. Install it under Modules, then Store.
