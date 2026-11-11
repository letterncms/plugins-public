# SEO

Search and generative engine optimization: meta tags, social cards, JSON-LD, sitemap, robots and AI crawlers, llms.txt, Markdown twins, redirects and on-page checks. Every collection with pages gets an SEO panel, and the home screen a widget with what to fix first.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/seo
```

## Settings

Under Settings → SEO, kept in `config/seo.yaml`:

- **Title Format** (`title_format`): the `<title>` of every page, from `{title}` and `{site}`.
- **Home Page Title** (`home_title`): the home page's title; the site name when empty.
- **Default Social Image** (`default_image`): shown for shared links of pages without their own.
- **Type**, **Name**, **Logo**, **Profiles** (`org_type`, `org_name`, `org_logo`, `same_as`): who the site is by, for structured data.
- **Summary for AI (llms.txt)** (`llms_summary`): who you are and what you offer, at the top of `/llms.txt`.
- **Publish Markdown Versions of Pages** (`markdown_twins`): each page also at its address with `.md`. On by default.
- **Block These AI Crawlers** (`blocked_crawlers`): crawlers `robots.txt` turns away.
- **Security Contact** (`security_contact`): where to report vulnerabilities; publishes `/.well-known/security.txt`.
- **Discourage All Indexing** (`discourage_indexing`): for staging sites.

The Redirects tab beside them edits `content/redirects.yaml`.

## Abilities

- `seo/analyze`: the on-page checks of one entry, with a score. Needs to view the entry's collection.
- `seo/health`: the same checks across published pages: average score, common issues and the lowest-scoring pages. Needs `admin.access`.
- `seo/markdown`: the Markdown version of a page that AI crawlers receive. Needs `admin.access`.
- `redirects/list`: the redirects. Needs `seo.manage`.
- `redirects/create`: adds one redirect. Needs `seo.manage`.
- `redirects/set`: puts the whole redirect list in place; what is left out is gone. Needs `seo.manage`.

Each says what it answers with (`output`). The permission `seo.manage` (**Manage SEO Settings and Redirects**) comes with the Editor role.

## Hooks

- `seo.alternates` (filter): `fn (array $alternates, Entry $entry): array`. The same page in other languages, each `['locale' => 'fr', 'uri' => '/fr/about']`, for `hreflang` links in the head and the sitemap.
- `seo.sitemap` (filter): `fn (array $urls): array`. Addresses your plugin answers itself, each `['uri' => '/shop', 'updated' => '2026-11-01']`, added to the sitemap.
- `redirects.saved` (action): after the redirect list changed.
- `render.structured` (action it listens to): `dispatch('render.structured', string $key, array $data)` while a page renders adds a node to the page's JSON-LD.
