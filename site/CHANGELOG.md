# Changelog

## Unreleased

### Added

- The starter's header shows **Sign in**, or **My account** once someone is signed in, on sites with public accounts turned on, on phones too.

### Changed

- The design system and custom code no longer check the core's `CMS_LOCKDOWN`, which is gone. This version works with every 0.x of the core; the previous one stops saving on a core without lockdown.

## 0.2.0 (2026-11-02)

### Changed

- **Preview** moved from the editor's top bar into its **More** menu.

### Added

- Adds `/adjust-design` to the assistant's chat, and a prompt for MCP clients.

## 0.1.0 (2026-11-01)

### Added

- Navigation from a tree: `entry.root`, `item.current` and `item.active` on entries, child pages of the top of a branch, and **Group by** on Repeat. `cms links:check` fails on links, images and anchors in the built site that lead nowhere.

### Fixed

- A static build removes generated files nothing produces any more, such as the sitemap of a language the site no longer has.
- A visitor who hits a rate limit, such as more than 20 checkout posts in a minute, now sees the site's own page with a plain message and a way back, instead of a bare 429. A checkout form left open past its session goes back to the form.
