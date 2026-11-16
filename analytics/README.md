# Analytics

Site analytics on your own server, with no cookies and nothing sent to another company. By default it counts page views per day with no script on the page; visitors mode adds visits, sources and channels (search engines, AI assistants, social networks, email, ads), countries, devices, time on page, scroll depth, goals, funnels, paths and page speed. Google Search Console shows next to it: the searches people find the site with, and the ones it is close to the top for.

Visitors mode adds a small script that skips people who opt out (Do Not Track, Global Privacy Control) and, unless you turn that off, waits for consent: `window.cmsConsent.analytics === true` or a `cms:consent` event. A visitor is a hash of IP address, browser and the site's host with a salt replaced every day, so today's visits can be told apart and nobody is recognised tomorrow. The country comes from the browser's time zone; no address is looked up.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/analytics
```

## Settings

On the Settings tab of Analytics, kept in `config/analytics.yaml`:

- **Count Visits** (`enabled`): counts views on the published site. On by default.
- **What to Count** (`mode`): `counts`, page views only, or `visitors`, with everything else. Switching back to page views deletes visitor data.
- **Wait for Consent** (`consent_required`): in visitors mode, count only once the visitor agreed. On by default.
- **Count Links, Downloads and Forms** (`automatic`): in visitors mode, count clicks on links to other sites, downloads of files and forms sent, as the events Outbound Link, File Download and Form Sent. On by default.
- **Paths Not to Count** (`exclude`): paths or patterns such as `/drafts/*`.
- **Keep Visits For** (`retention_days`): days to keep visits; `0` keeps them forever. 730 by default.
- **Reporting Address** (`endpoint`): the CMS's address, when the built site is hosted elsewhere.
- **Goal Pages** (`goals`): pages that count as reaching a goal, each a name and a page (`/thank-you`, `/welcome/*`). Every event is a goal by its name as well.
- **Funnels** (`funnels`): a name and steps, each a page (starting with `/`) or a goal's name, in order.
- **Service Account Key** (`search_key`, kept encrypted): the JSON key of a Google Cloud service account added as a user of the site's Search Console property, with the Search Console API turned on.
- **Search Console Property** (`search_property`): `sc-domain:example.com` or `https://example.com/`. Empty: the one for the site's host the account can see.

## Abilities

Every ability takes a `period` (`realtime`, `today`, `yesterday`, `7d`, `30d`, `90d`, `12mo`, or `custom` with `from` and `to`) and, where it reads visits, `filters`: a list of `{dimension, op, value}` with a dimension of `page`, `goal`, `entry`, `exit`, `channel`, `source`, `medium`, `campaign`, `country`, `device`, `browser` or `os`, an op of `is`, `is_not` or `contains`, and a value (`*` in a page for anything, `null` for none). They answer with aggregates only, and say what they answer with (`output`), for agents, the API and the CLI. All but `analytics/track` need `analytics.view` (**View Site Analytics**, which comes with the Editor role).

- `analytics/summary`: visitors, visits, page views, views per visit, bounce rate, visit duration, time on page and scroll depth for the period and the one before, a row per minute, hour, day, week or month, and who is on the site now; with a goal filter also conversions and the conversion rate; with `compare` the period before step by step. In counts mode only page views, narrowed to pages only.
- `analytics/breakdown`: visitors per page (with views, time on page and scroll depth), entry or exit page, channel, source, campaign, country, device, browser, operating system, language, goal (visitors, how often, conversion rate) or the properties of a goal's events.
- `analytics/funnel`: visitors per step of a funnel of the settings or of steps given, in order within a visit, with the share that dropped off.
- `analytics/paths`: the sequences of pages visits start with most, or where visitors of one page came from and went next.
- `analytics/vitals`: Core Web Vitals as visitors met them (Largest Contentful Paint, Interaction to Next Paint, Cumulative Layout Shift), the 75th percentile with Google's rating, for the site and per page.
- `analytics/search`: Google Search Console: `summary` (clicks, impressions, click-through rate, average position), `queries`, `pages`, `countries`, `devices`, `opportunities` (searches at positions 4 to 20, most shown first) or `status`. Narrows to one `page` or `query`.
- `analytics/goals`: the goal pages and funnels of the settings, and the events the script counts by itself.
- `analytics/track`: count an event from outside the published pages (a sale, a confirmed sign-up): a name, up to 10 properties and a page. Needs `analytics.track` (**Record Analytics Events**, which comes with the Admin role).

## Hooks

- `analytics.track` (action): `dispatch('analytics.track', string $name, array $props = [], ?string $path = null)`. Any plugin counts an event of its own this way without depending on Analytics; with Analytics off or not installed, nothing happens.
- `analytics.known-path` (filter): `fn (bool $known, string $path): bool`. Return `true` for an address your plugin answers itself, so views of it are counted; pages of the site are known already.
- Uses the core's `site.policy` filter: a Reporting Address on another host is named in the public pages' content security policy (`connect-src`, `img-src`).

On a published page, `window.cmsAnalytics.track(name, props)` counts an event of the site's own, and an element with `data-cms-event="Sign Up"` (and `data-cms-event-plan="Pro"` for a property) counts each click on it.
