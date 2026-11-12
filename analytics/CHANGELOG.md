# Changelog

## Unreleased

## 0.9.0 (2026-11-12)

### Added

- With the site's content security policy on, a **Reporting Address** on another host still receives the views: Analytics names it in the policy (`site.policy`).

### Changed

- Needs CMS 0.26: going back to page views asks first through the core's `confirmChange`, which an older CMS does not have.
- Going back to counting page views only asks first, as it is saved, and says it deletes the visitor data: the sentence under the choice is gone.
- A number has one name everywhere: the lists' column is **Page Views**, like the number above the chart and on the home screen. On a phone a row says what it counts: “4,048 Page Views”.
- **What to Count** says what switching back costs: visitor data is deleted.
- The home-screen widget's empty state is its title, without a sentence under it.

### Fixed

- **Top Pages** reads a page's title whole; the path beside it gives way first, and the numbers take less room. Titles were cut to "A." and "Audi…".


## 0.8.0 (2026-11-11)

### Added

- `analytics/summary` and `analytics/breakdown` say what they answer with (`output`), for agents, the API and the CLI.

### Changed

- In German, the **Views** column reads “Ansichten”: the core uses the same English word for a list’s saved views, and the admin reads one dictionary for every package, so the word has one translation. **Page Views** stays “Seitenaufrufe”.
- The Analytics screen leads to its settings one way, the **Settings** tab: the line and the button under the lists are gone.
- The Analytics settings say less: no notes under the fields. **Keep Visits For** left empty reads **Forever**.
- The overview shows its numbers as plain figures, and with visitors the figures themselves choose which one the chart draws: press one, and a line over it marks it. With page views alone there is one figure and no tab bar, and the line saying what the change is compared with is a tooltip on the figure. The lists fit a phone: the page's name gives way before the bar and the number.

### Fixed

- The chart’s axis counts in whole numbers: one page view is no longer drawn against 0.5.

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
