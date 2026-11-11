# Analytics

Site analytics on your own server: page views per day by default, with visitors, sources and devices as an option. In the default mode a published page carries one image and nothing is read from the browser; visitors mode adds a small script that skips people who opt out (Do Not Track, Global Privacy Control) and, unless you turn that off, waits for consent: `window.cmsConsent.analytics === true` or a `cms:consent` event.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/analytics
```

## Settings

On the Settings tab of Analytics, kept in `config/analytics.yaml`:

- **Count Visits** (`enabled`): counts views on the published site. On by default.
- **What to Count** (`mode`): `counts`, page views only, or `visitors`, with visitors, sources and devices. Switching back to page views deletes visitor data.
- **Wait for Consent** (`consent_required`): in visitors mode, count only once the visitor agreed. On by default.
- **Paths Not to Count** (`exclude`): paths or patterns such as `/drafts/*`.
- **Keep Visits For** (`retention_days`): days to keep visits; `0` keeps them forever. 730 by default.
- **Reporting Address** (`endpoint`): the CMS's address, when the built site is hosted elsewhere.

## Abilities

- `analytics/summary`: page views for the last 7, 30 or 90 days, the period before to compare, and a row per day; in visitors mode also visitors, views per visit, bounce rate and who is on the site now. Needs `analytics.view`.
- `analytics/breakdown`: views per page or language; in visitors mode also per entry page, source, device, browser or operating system. Needs `analytics.view`.

Each says what it answers with (`output`). The permission `analytics.view` (**View Site Analytics**) comes with the Editor role.

## Hooks

- `analytics.known-path` (filter): `fn (bool $known, string $path): bool`. Return `true` for an address your plugin answers itself, so views of it are counted; pages of the site are known already.
