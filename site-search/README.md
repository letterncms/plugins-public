# Site Search

Adds a **Search Field** and a **Search Results** block, for sites people mostly search, such as a council's site or a large help centre. The field goes on any page: in the header, on the home page, on a page of its own. It sends what was typed to the results page as a plain link (`/search?q=bins`), so it works without JavaScript too. On the results page the field shows what was searched for.

The results page searches the index the site already makes for its pages (`/search.json`), in the browser, so it needs no server of its own and works on a static site. It finds pages that have every word typed, and the start of a word too, so "recycl" finds "recycling"; accents and capitals don't matter. A match in the title counts most, then the page's keywords, its headings, its description and its text. A page found only by one of its headings opens at that heading. Each result shows the kind of page it is ("Service", "News"), the date for a dated collection, and its description or a piece of its text with the words marked. With more than one kind among them, links above the results show only one kind, with how many each has. "Show More Results" adds the next ones and moves the focus to the first of them. A screen reader hears how many were found, and the tab's title starts with what was searched for. On a site in several languages, only pages in the page's own language are found.

Both blocks are built to WCAG 2.2 AA: the field always has a label (hidden unless you show it, and still read), the search is a landmark, the results are a list of headings with links, and the status line is announced. Without JavaScript the results page says so, in words you choose.

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/site-search
```

Then make a page at `/search`, or any address, and put a **Search Results** block on it. Put a **Search Field** wherever people should find it, with **Results Page** set to that page when it isn't `/search`. The site's **Publish a Search Index** setting must be on; it is by default.

## Settings

The plugin has no settings of its own. To let editors give a page words it should also be found by ("bin" for a page on waste collection), add a field named `keywords` to the collection, a text or a list.

The **Search Field** block has these:

| Setting | What it does |
|---|---|
| **Label** (`label`) | What the field is called. Empty: "Search". |
| **Show Label** (`show_label`) | Off by default: the label is hidden, and screen readers still read it. |
| **Placeholder** (`placeholder`) | Words shown in the empty field. Empty by default. |
| **Button Label** (`button_label`) | The button's words. Empty: "Search". |
| **Button Style** (`button_style`) | One of the site's button looks, `primary` by default. |
| **Icon Only Button** (`icon_only`) | A magnifying glass instead of words; the button label is still read out. |
| **Size** (`size`) | `sm`, `md` (default) or `lg`, for the field and the button together. |
| **Results Page** (`results_page`) | The page with the Search Results block, a link or a page of the site. Empty: `/search`. |

The **Search Results** block has these. In the texts, `{query}` is what was searched for and `{count}` how many were found.

| Setting | What it does |
|---|---|
| **Heading Level** (`heading_level`) | The heading of each result's title: `2` (default), `3` or `4`. |
| **Results Per Page** (`per_page`) | How many show at first, and how many more each press of "Show More Results" adds. 20 by default. |
| **Show Filters** (`show_filters`) | On by default: links that show one kind of page, when the results have more than one. |
| **No Query** (`no_query`) | Shown when nothing was typed. Empty: "Type what you are looking for." |
| **No Results** (`none`) | Empty: "No results for “{query}”". |
| **One Result** (`one`) | Empty: "1 result for “{query}”". |
| **Many Results** (`many`) | Empty: "{count} results for “{query}”". |
| **All Label** (`all_label`) | The filter for every kind. Empty: "All results". |
| **Filters Label** (`filters_label`) | What screen readers call the filters. Empty: "Filter results". |
| **Without JavaScript** (`no_script`) | Shown when scripts don't run. Empty: "Search needs JavaScript. Browse the site’s menu instead." |
| **More Label** (`more_label`) | Empty: "Show More Results". |
| **More Button Style** (`more_style`) | One of the site's button looks, `secondary` by default. |

In Block Markdown:

```markdown
::: site-search/form {"props":{"label":"Search the site","show_label":true,"placeholder":"Bins, parking, council tax…","results_page":"/search"}}
:::

::: site-search/results {"props":{"heading_level":2,"per_page":20}}
:::
```

The blocks' parts carry classes of their own (`cms-site-search-form__input`, `cms-site-search-results__item`, `cms-site-search-results__title`…) in a quiet look of the theme's colours, so classes on the blocks restyle them.

## Abilities

None of its own. Agents add the blocks like any other, through the `blocks/*` and `entries/*` abilities; `blocks/types` lists them with an example.

## Hooks

It adds `kind`, `date` and `keywords` to each page's record through the core's `site.search.record` filter, and names the button classes it writes through `styling.candidates`. It adds no hooks of its own.
