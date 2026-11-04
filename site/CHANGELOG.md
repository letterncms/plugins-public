# Changelog

## Unreleased

## 0.4.0 (2026-11-04)

### Changed

- Needs the CMS 0.4 or later.
- The **Design System** is in the sidebar's Design section, with its sections (Colors, Typography…) as rows under it, so the sidebar no longer swaps when you open it. Its changes are saved from the save bar, and the starter's home page says where to find it.
- In the Design System, **Elements** and **Buttons** are edited with controls instead of CSS rows: font, size, weight, line height, letter spacing and color, and for boxes fill, border, radius and padding, each offering the site's tokens. Each element and button shows how it looks and opens to its controls; a button's Default and Hover are picked by clicking them. Properties no control covers stay editable as CSS.
- Font files are part of **Typography**, under the font families, instead of a section of their own. A font family is picked from the site's fonts with what it falls back to.
- Every token's value has the same control and width: numbers with their unit (scrub, type or pick a unit), shadows and easings as named presets with a custom one, and the page transition's duration in ms like the durations.
- **Color** is **Colors**, names like *primary-foreground* fit, Custom CSS and JavaScript look alike, and on a phone the panel takes the screen.
- The **Template** picker offers the inherited template once, as **Default (Page)**, instead of listing it twice. **Preview** gives way to the editor's **View on Site** while a live page has nothing unpublished.
- General says less: no notes under **Description** and **Build a Static Site**, and the favicon’s is “SVG or PNG, square.” The permission is **Publish the Site**.

- The Design System's preview shows the desktop layout on **Desktop** at any width, scaled down when there is less room.
- Every control in the Design System is one height, font selects and the Google font's **Add** included. Spacing bars and duration samples are neutral instead of blue, a button's **Opacity** is a percentage, and a fluid size names its unit once when phone and desktop share it.
- A Repeat of entries reads plainly: **Only Entries Where** is a list of conditions (a field, is / is not / contains…, a value) instead of a JSON box, and **Paginate** is **Split into Pages**.
- The command palette’s **Publish the Whole Site** is **Publish Site**, like the Dashboard’s button.

### Fixed

- The **Publishing** heading in General is in Dutch and French too.
- The starter ships a *Footer* menu (Home, About, Blog), so its footer no longer shows placeholder links.

## 0.3.0 (2026-11-02)

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
